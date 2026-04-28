<?php
// FILE: ahmad/download.php

// 1. Tentukan path ke file data LOKAL (milik Ahmad)
$dataFile = __DIR__ . '/data_amalan.json';

// 2. Muat seluruh logika download dari folder 'test'
require '../../test/download.php';