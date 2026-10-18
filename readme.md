![Screenshoot Halaman](./test/assets/demo/img_e1b59c09.png)

# amalanyaumiyah.xo.je

## command git ftp push
```pwsh
git ftp push
```

## jangan lupa commit dulu sebelum push
```pwsh
git add .
git commit -m "update"
```

## Menarik perubahan dari server (folder/file baru)
```pwsh
./pull.sh            # pull biasa + pindai folder/file baru (2 tingkat, cepat)
./pull.sh new        # hanya pindai folder/file baru di server
./pull.sh new --deep # pindai SELURUH pohon server (lambat, ~3-5 menit)
./pull.sh new --with-data  # sekalian tarik data_amalan.json
```
Aman: tidak pernah menghapus/menimpa file lokal (`--only-missing`), mengikuti
pengecualian `.git-ftp-ignore`, dan secara default **tidak** menarik file data
`*.json`. Berguna untuk mengambil grup/mentee yang dibuat langsung dari panel
admin di server.

## Install PHP
```pwsh
# Download and install PHP.
powershell -c "& ([ScriptBlock]::Create((irm 'https://www.php.net/include/download-instructions/windows.ps1'))) -Version 8.5"
```

## Run PHP
```pwsh
php -S localhost:8000
http://localhost:8000
```

## Cek php error
```pwsh
php -l index.php
```

## Login admin
Kredensial admin dibuat sekali dengan skrip:
```pwsh
./create-admin.sh
```
Hasilnya `test/admin.json` berisi username + password terenkripsi (AES-256-GCM).
File itu tidak ikut ke GitHub, tetapi ikut ke server FTP lewat `.git-ftp-include`.
`test/admin.php` menampilkan halaman login selama belum masuk. Skrip menolak
berjalan bila `admin.json` sudah ada — hapus dulu jika ingin membuat ulang.

Kunci kredensial **tidak ditulis apa adanya** di `admin.json`. Skrip membungkusnya
dengan bahan internal yang ditempel (disamarkan) ke `test/admin.php` pada konstanta
`ADM_ASSET_REV` (terlihat seperti revisi cache aset, sekaligus dipakai untuk
cache-busting ikon). Jadi menyalin `admin.json` saja tidak cukup untuk membuka
kredensial; `admin.php` juga dibutuhkan.

Sesi login diikat ke file `admin.json`. Bila file itu dihapus, rusak, atau dibuat
ulang, sesi lama otomatis gugur — admin yang masih membuka halaman akan diminta
masuk lagi.

## Pembuat nama panggilan (tool mandiri)
Panel di halaman admin (kode mandiri di dalam `admin.php`): masukkan nama lengkap →
muncul usulan nama panggilan beserta status tersedia/dipakai. Dicek terhadap semua
nama mentee (semua grup + tempat sampah), mentee yang tidak lagi mentoring, dan nama grup.
Tanpa angka, dan tidak mengubah apa pun.

## Tempat sampah & "tidak lagi mentoring"
Struktur seragam: `.DEPRECATED/<grup>/<mentee>/`. Folder `.DEPRECATED/<grup>` = grup yang
**tidak aktif** di root; menteenya bisa masih ada (sekadar belum diatur), jadi semuanya tetap ditampilkan.

- Panel Tempat Sampah menampilkan **tiap grup di `.DEPRECATED` beserta daftar menteenya** (seperti sebelumnya),
  dengan penanda **grup aktif** / **grup tidak aktif**.
- Grup **tidak aktif**: ada tombol **Pulihkan grup** (memindahkan `<grup>` kembali ke root).
- Setiap mentee di `.DEPRECATED` punya tombol **Pulihkan** dan **Tidak lagi mentoring**:
  - **Pulihkan** (grup tujuan sudah aktif di root): mentee dikembalikan ke grup itu.
  - **Pulihkan** (grup tujuan masih tidak aktif): grup `<grup>` dipulihkan ke root **berisi mentee ini saja**
    (file tingkat grup disalin dari `.DEPRECATED/<grup>`), mentee lain di grup itu tetap di tempat sampah.
  - **Tidak lagi mentoring** → memindahkannya ke `.DEPRECATED/.OUT/`.
- **Tidak lagi mentoring**: `.DEPRECATED/.OUT/<mentee>/`. Tampil di bagian "Tidak lagi mentoring" dengan
  **warna khusus** (violet) dan tombol **Aktif kembali** + dropdown grup tujuan.
  - Dropdown berisi **semua grup** (aktif di root maupun yang ada di `.DEPRECATED`).
  - Memilih grup yang ada di `.DEPRECATED` **tidak** memulihkan grupnya; menteenya cukup ditaruh di situ
    (opsi ditandai `(tidak aktif)`).
  - Manifest hanya dibuat ulang bila grup tujuannya aktif di root.
- Jadi tombol **Aktif kembali** hanya untuk mentee berstatus tidak lagi mentoring; mentee yang sekadar
  dihapus cukup memakai **Pulihkan**.

### Completed
- `admin.php` + `test/bi.php`: **target khusus halaman analisis "semua kelompok" (lintas grup)**. Halaman lintas grup kini bisa punya aturan target sendiri di **`test/bi/target_settings.json`** — nama berkas sama, hanya disimpan di folder halaman analisisnya, sehingga **tidak** memakai `target_settings.json` milik grup mana pun. Panel baru **"Target khusus — Analisis Semua Kelompok"** memakai editor yang sama; hanya field **Group** disembunyikan (nilainya tersimpan `LINTAS GRUP`), catatan menampilkan berkas tujuan, dan simpan memakai aksi terpisah **`save_targets_all`** (tanpa `group`). Halaman grup tetap memakai `target_settings.json` miliknya sendiri. Bila berkasnya belum ada, halaman lintas grup tetap memakai perbandingan capaian umum seperti sebelumnya (tanpa panel target) — jadi membuatnya bersifat opsional. Berkas ini ikut ke Git (pola `!target_settings.json`) dan ke server FTP (tidak kena `.git-ftp-ignore`).
- `test/bi.php`: label **sumber data** di halaman analisis kini benar untuk mode lintas grup. Sebelumnya `/test/bi/` menulis *"Data diambil dari folder test."* dan menyebut `target_settings.json` seolah-olah milik folder `test`. Kini label mengikuti mode analisis: grup → *"folder \<nama grup\>"* + `\<grup\>/target_settings.json`; semua kelompok → *"N kelompok (FTJ, BPH, …)"* + `bi/target_settings.json (target khusus lintas grup)`.
- `pull.sh`: **aksi baru `new`** untuk menarik folder/subfolder + file yang dibuat **langsung di server** (mis. grup/mentee baru dari panel admin live). Sebelumnya `git ftp pull --changed-only` hanya mengunduh file yang ada di `git diff` sehingga file buatan server tak pernah ikut. Aksi ini memindai server dengan `lftp mirror --only-missing` (**tidak pernah `--delete`**, jadi file lokal aman), mengikuti pengecualian `.git-ftp-ignore` + dotfile. Default = pemindaian **2 tingkat** (cepat, ~20-30 dtk); `--deep` memeriksa **seluruh** pohon (lambat, ~3-5 menit); `--with-data` menyertakan `data_amalan.json`/`hijri_cache.json` (default dikecualikan). Aksi `pull` sekarang menjalankan `git ftp pull --changed-only --no-commit` **lalu** pemindaian tambahan ini.
- `admin.php`: editor `target_settings.json` dibuat lebih nyaman & **dinamis**: `data_keys` kini **chip yang bisa diklik** (bukan lagi mengetik koma) plus kolom untuk key lain; **Target (teks) terisi otomatis** dari jumlah + satuan + periode (tombol **Auto** untuk kembali ke mode otomatis, bisa ditimpa manual); tiap mode punya **penjelasan singkat**; dan ada **ringkasan langsung** per aturan sehingga hasil pengaturan terlihat sebelum disimpan. Format JSON yang dikonsumsi halaman analisis tidak berubah.
- `admin.php`: cegah target yang **selalu 0%** karena `match_value` salah. Kunci centang (checkbox) — `dhuha`, `tahajud`, `witir`, `senin_kamis`, `ayamul_bidh` — tersimpan sebagai `"✓"`, sedangkan sholat wajib menyimpan kode kehadiran (`"M"`, `"R"`, `"Q"`). Sebelumnya contoh prompt AI memakai `match_value:"1"` sehingga model meniru nilai itu dan capaian puasa sunah selalu 0. Kini: contoh prompt diperbaiki (`"M"`/`"✓"`), ada peringatan tegas di **dua** validator (`ai_validate_targets` + `adm_validate_target_structure`) yang menolak `match_value` tidak cocok untuk kunci centang, hasil AI dinormalkan otomatis (`"1"`/`"0"`/`"yes"` → `"✓"`), dan petunjuk placeholder field `match_value` menyebut nilai yang benar. Untuk "sudah dikerjakan" dianjurkan mode `day_presence` (tanpa `match_value`).
- `admin.php` (editor target): field **`match_value`** kini **chip dinamis**, bukan lagi isian teks bebas. Pilihan menyesuaikan **Data keys** yang dipilih: sholat wajib (`subuh`…`isya`) → `M`, `R`, `R-J`, `M-S`, `Q` (dengan keterangan Masjid/Rumah/Jamaah/Qadha); kunci centang (`dhuha`, `tahajud`, `witir`, `senin_kamis`, `ayamul_bidh`, `sedekah`, `almatsurat_pagi/petang`) → `✓`; kombinasi keduanya menampilkan gabungan pilihan. Bila `Data keys` belum dipilih, muncul petunjuk. Nilai yang tidak lagi valid otomatis dibuang saat kunci berubah, dan boleh memilih lebih dari satu (disimpan dipisah koma, mis. `M,R-J`).
- `test/target_engine.php` + `admin.php`: `match_value` untuk mode `value_count` kini boleh berisi **beberapa nilai dipisah koma** (mis. `"M,R-J"` untuk sholat berjamaah = Masjid/Jamaah + Rumah/Jamaah). Sebelumnya hanya satu nilai persis. Dua validator (`adm_validate_target_structure` + `ai_validate_targets`) memakai helper bersama `adm_target_rule_problems()` + `adm_key_value_groups()` yang menolak aturan **mustahil tercapai** (capaian selalu 0): `match_value` yang tidak cocok (kunci centang harus `"✓"`; sholat wajib harus kode sah `M`, `R-J`, `M-S`, `R`, `Q` — jadi `"1"` langsung ditolak); mode `numeric` untuk kunci yang bukan angka (hanya `istighfar` yang angka — dhuha/witir/tilawah/rawatib bukan); `day_presence` periode harian dengan `target_count > 1`; `filled_key_count` harian melebihi jumlah `data_keys`; serta `quran_sheet_count` tanpa kunci `tilawah`.
- `admin.php` (tool **Aturan otomatis dari deskripsi (AI)**): prompt diperkuat dengan **pemetaan kunci → mode** yang tegas (sholat wajib → `value_count` + kode M/R-J/…; kunci centang → `day_presence`; `rawatib` → `rawatib_count`; `tilawah` → `quran_sheet_count`; almatsurat → `filled_key_count`; istighfar → `numeric`) dan aturan periode (target "X kali per pekan/bulan" memakai `period` week/month; `day_presence` harian maksimal 1). Hasil AI juga **dinormalkan otomatis** sebelum validasi: `numeric` pada kunci non-angka → `day_presence`; `match_value "1"/"0"/…` pada kunci centang → `"✓"`; `day_presence` harian `target_count > 1` → 1. Bila tetap tidak valid, issue GitHub dibuat seperti sebelumnya.
- `bph/target_settings.json`: perbaiki rule yang selalu 0% — "Sholat Berjamaah" `match_value "1"` → `"M,R-J"`; "Sholat Dhuha" & "Sholat Witir" `numeric` → `day_presence` 1/hari; "Tilawah Qur'an" `day_presence` 3/hari → `quran_sheet_count` 1 lembar/hari; "Sholat Rawatib" `day_presence` → `rawatib_count` 4 rakaat/hari (`rakaat_per_check` 2).
- `test/index.php`: perbaiki grafik **"bayangan target"** di kartu target. Batang **isi capaian** sebelumnya dibuat lebih sempit (`left:15%;right:15%`) daripada batang **bayangan target**, sehingga meski capaian mencapai target, isinya tetap terlihat lebih kecil dari bayangannya (mismatch ukuran). Kini isi memakai lebar penuh (`left:0;right:0`) sehingga saat target tercapai isi **tepat menutupi** bayangan (berwarna hijau), dan sisa target tampak sebagai pita di atas isi. Sekaligus teks **"capaian X dari Y"** kini menampilkan angka **apa adanya** (tidak lagi dibatasi target per periode), jadi bila capaian melebihi target akan tertulis mis. **"capaian 5 dari 3"**. Mesin target menambah field `done_raw` (capaian mentah) di samping `done` (yang tetap dibatasi agar persentase tidak melebihi 100%).
- `admin.php`: tombol **Salin pesan halaman analisis** di sebelah **Salin pesan**. Sekali klik langsung menyalin pesan berisi tautan halaman analisis grup (`/<grup>/index.php`) tanpa popup, dengan umpan balik "Tersalin ✓".
- `test/bi.php` (halaman analisis): **pemilih model grafik** yang bisa diganti sesuka hati — **Kartu**, **Batang**, **Garis**, **Donat**, **Radar**, dan **Peta Panas** — untuk bagian *Kinerja per Kategori* dan *Rincian Capaian Target*. Digambar dengan SVG/HTML murni (tanpa pustaka luar), data disematkan sebagai JSON, dan pilihan terakhir diingat lewat `localStorage`. Penanda target (garis putus-putus) tetap tampil di model Batang/Garis/Radar.
- `test/bi.php`: warna **pemilih model grafik** dan **isi grafik** disamakan dengan tema yang benar-benar aktif (Material 3 terang). Sebelumnya warna memakai palet tema gelap yang sudah tertimpa: wadah pemilih jadi kotak gelap, garis bantu grafik nyaris tak terlihat di latar terang, dan isian area/radar terlalu pucat. Kini pemilih memakai gaya *segmented button* MD3 (wadah `surface-container-low` + garis `outline`, tab aktif `secondary-container` + `on-secondary-container` — sama dengan warna chip `.tag`), garis bantu `outline-variant`, area/radar memakai `primary-container` + `primary`/`tertiary`, dan warna peta panas dihitung langsung sebagai `hsl()` bentuk klasik agar aman di browser/WebView lama. Peta panas juga diberi **legenda skala 0%–100%** dan sel yang seragam (bukan lagi mirip kartu).
- `test/bi.php`: label sumbu pada model **Donat** dan **Radar** kini **adaptif** agar tidak terpotong/tertimpa. Lebar tiap label diukur sungguhan lewat `canvas.measureText()` (bukan perkiraan jumlah huruf), lalu ukuran diagram dicari otomatis: pada **Donat**, radius dikecilkan bertahap dan kolom legenda melebar sampai semua nama muat (nomor persen tak pernah tertimpa); pada **Radar**, beberapa ukuran (dan opsi label berselang-seling) dicoba lalu dipilih yang paling banyak menampilkan teks tanpa ada kotak label yang saling menimpa. Bila ruangnya memang tidak cukup (mis. 12 label panjang), label dipotong rapi dengan `…` dan nama lengkapnya tetap muncul sebagai tooltip. Tinggi kanvas donat juga menyesuaikan jumlah baris. Sekaligus memperbaiki ukuran angka besar di tengah donat yang sebelumnya selalu terpaksa 11px karena tertimpa aturan kelas `.bi-value-text` (kini diatur lewat `style`).
- `admin.php`: salinan manifest lokal di `test/_manifest/` kini **dipangkas otomatis** (`adm_prune_local_manifests()`). Setiap kali manifest ditulis (juga saat mentee/grup dihapus, ditandai "tidak lagi mentoring", atau grup dihapus), file `manifest_*.json` yang tidak lagi cocok dengan grup aktif + menteenya akan dibuang. `manifest_test.json` selalu dipertahankan. Grup yang dipulihkan akan membuat ulang manifest grup + menteenya. Ini mencegah folder menumpuk sisa mentee/grup yang sudah dihapus (yang bisa ikut menumpuk di server bila panel dipakai di server).
- `admin.php`: perbaiki tombol **Uji koneksi** & **Daftar file di repo** yang seolah "tidak menghasilkan apa-apa". Penyebabnya: (a) aksi ini mengembalikan `ok:true` sehingga JS memuat ulang halaman 0,7 dtk kemudian dan menghapus pesannya, dan (b) hasilnya hanya muncul di `#msgs` di atas halaman, jauh dari tombol. Kini kedua tombol bertanda `data-noreload` (tak memuat ulang halaman) dan area pesan otomatis di-scroll ke tampilan. Saat menunggu, muncul "Memproses…" lebih dulu (panggilan GitHub bisa perlu beberapa detik). Hasil **Daftar file di repo** juga akhirnya dirender ke panel "3. Isi repo" (`#gh-files`) lengkap dengan tautan raw — sebelumnya `d.files` dari server tidak pernah ditampilkan.
- `admin.php`: perbaiki tombol **Simpan token**. Sebelumnya JS mengirim isi kolom token sebagai field `name` (bukan `token`), jadi server menganggap token kosong → token lama ikut terhapus lalu halaman dimuat ulang tanpa perubahan ("malah refresh, tak ada yang berubah"). Kini tombol memakai `data-from-field="token"` sehingga field yang dikirim benar; verifikasi gagal tidak lagi memuat ulang halaman sehingga pesan error tetap terbaca dan isian token tidak hilang.
- `admin.php`: token GitHub kini menerima **fine-grained** (`github_pat_...`) maupun **classic** (`ghp_...`). Sebelumnya validasi hanya menerima classic sehingga token fine-grained selalu ditolak ("Format token tidak dikenali") walau tetap di-commit/di-uji lewat API. Verifikasi saat menyimpan kini mengecek akses ke repo manifest + izin tulis (Contents: Read and write), dengan pesan error yang menyebut langkah perbaikan untuk fine-grained (pilih repo di "Only select repositories"). Panel menampilkan jenis token yang tersimpan.
- `create-admin.sh` + `admin.php`: kredensial admin dienkripsi AES-256-GCM di `test/admin.json`, tetapi **kunci kredensial tidak ditulis apa adanya** — dibungkus dengan bahan internal yang ditempel (disamarkan) ke `admin.php` sebagai konstanta `ADM_ASSET_REV` (berkedok revisi cache aset, sekaligus dipakai untuk cache-busting ikon). Jadi `admin.json` saja tidak cukup untuk membuka kredensial. `create-admin.sh` menolak berjalan bila `admin.json` sudah ada.
- `admin.php`: sesi login diikat ke sidik jari `test/admin.json`; menghapus/mengganti file kredensial otomatis mengeluarkan sesi yang masih aktif.
- `admin.php`: panel **"Keamanan rahasia — status token & API key"** dengan tombol **Cek status** dan **Enkripsi semua** (sekali klik). Token GitHub manifest (`test/_manifest/.token`), kunci OpenRouter (`test/_ai/openrouter_key`), dan token GitHub issue (`test/_ai/github_token`) ditampilkan statusnya (PLAINTEXT / terenkripsi / belum diisi / terkunci) dalam tabel berwarna. Bila masih plaintext, tombol **Enkripsi semua** membuat **kunci enkripsi 32 byte** yang dibungkus dengan bahan internal lalu **ditambal ke `test/admin.json`** (bidang `secret_key`/`secret_key_iv`/`secret_key_tag` — tidak ditulis apa adanya), kemudian tiap berkas rahasia ditulis ulang sebagai amplop **AES-256-GCM**. Setelah aktif, setiap penulisan rahasia baru (Simpan token / Simpan koneksi) otomatis terenkripsi, dan pembacaan mendeteksi amplop lalu mendekripsinya. Sidik jari sesi diperbarui saat `admin.json` ditambal sehingga admin tidak ter-logout. Bila `admin.json` dibuat ulang (kunci hilang), berkas terenkripsi ditandai **terkunci** dan tidak dirusak — cukup isi ulang nilainya. `create-admin.sh` menolak menimpa `admin.json` karena itu juga akan menghilangkan kunci enkripsi ini.
- `admin.php`: `.DEPRECATED/.OUT/` = mentee yang **tidak lagi mentoring**. Tampil di panel Tempat Sampah dengan warna khusus (violet) + tombol **Aktif kembali**; dropdown grup memuat **semua grup** (aktif + yang di `.DEPRECATED`), dan memilih grup di `.DEPRECATED` tidak memulihkan grupnya (manifest hanya dibuat ulang bila grup aktif di root). Tombol **Tidak lagi mentoring** muncul untuk mentee di `.DEPRECATED`; namanya juga ikut dicek agar tidak jadi usulan duplikat di tool nama panggilan.
- `admin.php`: panel Tempat Sampah menampilkan **tiap grup di `.DEPRECATED` beserta daftar menteenya** (seperti sebelumnya), dengan penanda grup aktif/tidak aktif, tombol **Pulihkan grup** (grup tidak aktif) dan **Pulihkan** + **Tidak lagi mentoring** di tiap baris mentee. **Pulihkan** pada mentee dari grup tidak aktif akan memulihkan grupnya ke root berisi mentee itu saja.
- `admin.php`: tool mandiri **Pembuat Nama Panggilan** dari nama lengkap (blok kode tersendiri di dalam `admin.php`, ditampilkan sebagai panel di halaman admin). Menampilkan beberapa usulan (kata, inisial, gabungan) beserta status tersedia/dipakai, dicek terhadap semua nama mentee (semua grup + tempat sampah) dan nama grup; tanpa angka. Tidak terhubung ke fitur lain (tidak menyimpan/mengubah apa pun).
- `admin.php`: grup baru tidak lagi otomatis membuat `target_settings.json`; file itu dibuat hanya saat target disimpan dari editor "Target & analisis" (grup tanpa file itu tetap jalan lewat fallback analisis).
- `admin.php`: tambah tombol **Hapus grup** → memindahkan seluruh grup beserta menteenya ke `.DEPRECATED/<grup>/` (dengan konfirmasi) dan bagian "Grup di tempat sampah" untuk memulihkannya. Struktur tempat sampah seragam: `.DEPRECATED/<grup>/<mentee>/`; bila folder `<grup>` sudah ada (mis. menteenya lebih dulu dihapus), isinya digabung.
- `admin.php`: perbaiki checkbox "mentor". Sebelumnya listener ganda (`click` + `change`) membuat aksi terkirim dua kali sehingga status kembali semula; kini input/select hanya pakai `change`.
- Perbaiki deteksi perubahan: mengembalikan data ke nilai sebelumnya kini dianggap "tidak ada perubahan", tombol Simpan ikut hilang (dipakai baseline pembanding terpisah dari cache lokal).
- `admin.php`: kartu Tempat Sampah collapse secara default.
- Template `index.php` grup & mentee tidak lagi memakai manifest placeholder; admin mengisi `$manifestPath` otomatis saat grup/mentee dibuat.
- Tambah manifest dasbor grup (`manifest_<grup>.json`) + tombol "Tulis manifest grup"; "Tulis SEMUA manifest" kini mencakup manifest grup.
- Pecah kode menggunakan Riverpod style (Modular ES Modules).
- Pecah kode berdasarkan fungsi, satu kode satu fungsi.
- Hapus file/kode yang tidak digunakan (`test/prayer_slider_handler.php`, `test/test_run.php`).

## Catatan
