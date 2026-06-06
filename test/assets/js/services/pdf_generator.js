import { getSanitizedName, getDownloadTimestamp } from '../utils/format_helper.js';

export function downloadCurrentTableAsPdf() {
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
    const safeName = getSanitizedName(nameEl ? nameEl.textContent.trim() : '');
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

    const monthTitle = tableContainer.querySelector('h2') ? tableContainer.querySelector('h2').textContent : `Laporan Bulan: ${monthStr}`;
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(14);
    doc.text('Tracker Amalan Yaumiyah', 40, 40);
    doc.setFontSize(11);
    doc.text(monthTitle, 40, 58);

    doc.autoTable({
        html: table,
        startY: 74,
        margin: { left: 24, right: 24, bottom: 24 },
        styles: {
            fontSize: 7,
            cellPadding: 3,
            overflow: 'linebreak',
            valign: 'middle',
            font: 'helvetica'
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
