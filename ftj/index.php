<?php
// FILE: ftj/index.php

$manifestPath = 'https://raw.githubusercontent.com/krasyid822/for-manifest-json-pwa/main/manifest_ftj.json';
$folder_name = basename(__DIR__);
$namaPengguna = strtoupper(str_replace(['_', '-'], ' ', $folder_name));
$analysisRoot = __DIR__;

require_once __DIR__ . '/../test/bi.php';
