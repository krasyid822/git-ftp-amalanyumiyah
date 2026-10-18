<?php
// FILE: ftj/rasyid/download.php
//
// Rekan dari ftj/rasyid/index.php (stub "satu orang beberapa grup"). Tautan
// unduh di halaman relatif ke URL halaman, jadi saat halaman dirender in-place
// di /ftj/rasyid/index.php, tombol unduh mengarah ke /ftj/rasyid/download.php.
// Stub ini meneruskan ke test/download.php dengan grup yang sama.

$_GET['mentee'] = 'rasyid';
$_GET['group']  = 'ftj';

require __DIR__ . '/../../test/download.php';
