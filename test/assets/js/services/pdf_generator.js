import { getSanitizedName, getDownloadTimestamp } from '../utils/format_helper.js';

// ---------------------------------------------------------------------------
// Font Poppins untuk PDF.
// jsPDF tidak mengenal font sistem, jadi TTF harus ditanamkan (VFS base64).
// Font diambil dari ./assets/fonts/ (same-origin) dan di-cache per sesi supaya
// unduhan PDF berikutnya tidak memuat ulang.
// ---------------------------------------------------------------------------
let poppinsFontsPromise = null;

function arrayBufferToBase64(buffer) {
    let binary = '';
    const bytes = new Uint8Array(buffer);
    const chunkSize = 0x8000;
    for (let i = 0; i < bytes.length; i += chunkSize) {
        binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunkSize));
    }
    return btoa(binary);
}

function loadPoppinsFonts() {
    if (poppinsFontsPromise) return poppinsFontsPromise;

    const fontBase = new URL('../../fonts/', import.meta.url);
    const fetchFont = (file) =>
        fetch(new URL(file, fontBase)).then((res) => {
            if (!res.ok) throw new Error(`${file} gagal dimuat (${res.status})`);
            return res.arrayBuffer();
        });

    poppinsFontsPromise = Promise.all([
        fetchFont('Poppins-Regular.ttf'),
        fetchFont('Poppins-Bold.ttf')
    ]).then(([regular, bold]) => ({
        regular: arrayBufferToBase64(regular),
        bold: arrayBufferToBase64(bold)
    }));

    return poppinsFontsPromise;
}

async function registerPoppins(doc) {
    try {
        const fonts = await loadPoppinsFonts();
        doc.addFileToVFS('Poppins-Regular.ttf', fonts.regular);
        doc.addFont('Poppins-Regular.ttf', 'Poppins', 'normal');
        doc.addFileToVFS('Poppins-Bold.ttf', fonts.bold);
        doc.addFont('Poppins-Bold.ttf', 'Poppins', 'bold');
        return true;
    } catch (error) {
        console.warn('Font Poppins tidak bisa dimuat, memakai font bawaan PDF.', error);
        return false;
    }
}

export async function downloadCurrentTableAsPdf() {
    if (!window.jspdf || !window.jspdf.jsPDF) {
        alert('Fitur PDF belum siap. Coba muat ulang halaman.');
        return;
    }

    const tableContainer = document.querySelector('.table-container');
    const table = tableContainer ? tableContainer.querySelector('table') : null;
    if (!table) {
        alert('Tabel laporan tidak ditemukan.');
        return;
    }

    const nameEl = document.querySelector('.nama-pengguna');
    const displayName = nameEl ? nameEl.textContent.trim() : '';
    const safeName = getSanitizedName(displayName);
    const tanggalInput = document.getElementById('tanggal');
    const monthStr = tanggalInput && tanggalInput.value ? tanggalInput.value.substring(0, 7) : 'bulan-aktif';
    const timestamp = getDownloadTimestamp();
    const fileName = `laporan-tabel-${safeName}-${monthStr}_${timestamp}.pdf`;

    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a3' });
    if (typeof doc.autoTable !== 'function') {
        alert('Plugin PDF belum siap. Coba muat ulang halaman.');
        return;
    }

    // Pakai Poppins bila bisa dimuat; kalau tidak, jatuh ke helvetica bawaan.
    const usePoppins = await registerPoppins(doc);
    const fontFamily = usePoppins ? 'Poppins' : 'helvetica';

    const monthTitle = tableContainer.querySelector('h2') ? tableContainer.querySelector('h2').textContent : `Laporan Bulan: ${monthStr}`;
    doc.setFont(fontFamily, 'bold');
    doc.setFontSize(14);
    doc.text('Tracker Amalan Yaumiyah', 40, 40);

    doc.setFont(fontFamily, 'normal');
    doc.setFontSize(11);
    doc.text(monthTitle, 40, 58);

    // Nama mentee ikut dicetak di dalam PDF.
    if (displayName) {
        doc.setFont(fontFamily, 'bold');
        doc.text(`Nama: ${displayName}`, 40, 74);
    }

    doc.autoTable({
        html: table,
        startY: displayName ? 90 : 74,
        margin: { left: 24, right: 24, bottom: 24 },
        styles: {
            fontSize: 7,
            cellPadding: 3,
            overflow: 'linebreak',
            valign: 'middle',
            font: fontFamily
        },
        theme: 'grid',
        didParseCell: function(data) {
            if (data.section === 'head') {
                data.cell.styles.fillColor = [0, 106, 106]; // #006A6A (Primary Material You Color)
                data.cell.styles.textColor = [255, 255, 255];
                data.cell.styles.halign = 'center';
                data.cell.styles.fontStyle = 'bold';
            } else if (data.section === 'body') {
                const htmlCell = data.cell.raw;
                if (htmlCell) {
                    // Cek jenis kolom
                    if (htmlCell.classList.contains('kategori-utama')) {
                        data.cell.styles.fillColor = [228, 235, 234]; // #E4EBEA
                        data.cell.styles.textColor = [25, 28, 28];
                        data.cell.styles.halign = htmlCell.classList.contains('td-ibadah') ? 'left' : 'center';
                        data.cell.styles.fontStyle = 'bold';
                    } else if (htmlCell.classList.contains('td-ibadah')) {
                        data.cell.styles.fillColor = [255, 255, 255]; // #FFFFFF
                        data.cell.styles.textColor = [25, 28, 28];
                        data.cell.styles.halign = 'left';
                        data.cell.styles.fontStyle = 'normal';
                    } else {
                        // Ini adalah sel amalan (kolom tanggal)
                        data.cell.styles.halign = 'center';
                        if (htmlCell.classList.contains('status-good')) {
                            data.cell.styles.fillColor = [168, 245, 184]; // #A8F5B8
                            data.cell.styles.textColor = [20, 108, 46]; // #146C2E
                            data.cell.styles.fontStyle = 'bold';
                        } else if (htmlCell.classList.contains('status-ok-2')) {
                            data.cell.styles.fillColor = [194, 231, 255]; // #C2E7FF
                            data.cell.styles.textColor = [0, 101, 142]; // #00658E
                        } else if (htmlCell.classList.contains('status-ok-1')) {
                            data.cell.styles.fillColor = [255, 223, 158]; // #FFDF9E
                            data.cell.styles.textColor = [122, 89, 0]; // #7A5900
                        } else if (htmlCell.classList.contains('status-qadha')) {
                            data.cell.styles.fillColor = [255, 218, 214]; // #FFDAD6
                            data.cell.styles.textColor = [186, 26, 26]; // #BA1A1A
                        } else if (htmlCell.classList.contains('status-locked')) {
                            data.cell.styles.fillColor = [222, 229, 228]; // #DEE5E4
                            data.cell.styles.textColor = [111, 121, 120]; // #6F7978
                        } else if (htmlCell.classList.contains('status-empty')) {
                            data.cell.styles.fillColor = [234, 241, 240]; // #EAF1F0
                            data.cell.styles.textColor = [63, 73, 72]; // #3F4948
                        }
                    }
                }
            }
        }
    });

    doc.save(fileName);
}
