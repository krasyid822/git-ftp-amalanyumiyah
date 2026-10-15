<?php
// FILE: {{MENTEE}}/index.php
// Halaman mentee. Dibuat otomatis oleh test/admin.php.

// $manifestPath diisi otomatis oleh test/admin.php saat mentee dibuat.
$manifestPath = '';
$folder_name = basename(__DIR__);
$namaPengguna = ucwords(str_replace(['_', '-'], ' ', $folder_name));
$dataFile = __DIR__ . '/data_amalan.json';

require '../../test/index.php';
