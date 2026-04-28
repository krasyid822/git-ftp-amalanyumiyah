<?php
// FILE: ftj/index.php

$manifestPath = 'https://krasyid822.github.io/Drive/manifest_.json';
$folder_name = basename(__DIR__);
$namaPengguna = strtoupper(str_replace(['_', '-'], ' ', $folder_name));
$analysisRoot = __DIR__;

require_once __DIR__ . '/../test/bi.php';
