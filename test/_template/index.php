<?php
// FILE: {{GROUP}}/index.php
// Halaman grup. Dibuat otomatis oleh test/admin.php.

// $manifestPath diisi otomatis oleh test/admin.php saat grup dibuat / manifest diterbitkan.
$manifestPath = '';
$folder_name = basename(__DIR__);
$namaPengguna = strtoupper(str_replace(['_', '-'], ' ', $folder_name));
$analysisRoot = __DIR__;

require_once __DIR__ . '/../test/bi.php';
