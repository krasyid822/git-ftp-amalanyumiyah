<?php
$manifestPath = 'https://raw.githubusercontent.com/krasyid822/for-manifest-json-pwa/main/manifest_rasyid.json';
// Stub redirect: satu orang bisa ada di banyak grup, jadi tidak ada data_amalan.json
// di sini. Redirect ke test/ dengan parameter mentee & group agar test/index.php
// tahu data file & target settings mana yang harus dipakai.
$url_tujuan = "../../test/index.php?mentee=rasyid&group=ftj";

// Lakukan redirect
header("Location: " . $url_tujuan);
exit();
