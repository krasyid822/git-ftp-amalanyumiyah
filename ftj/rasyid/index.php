<?php
$manifestPath = 'https://raw.githubusercontent.com/krasyid822/for-manifest-json-pwa/main/manifest_rasyid.json';
// Tentukan URL tujuan redirect
$url_tujuan = "../../test";

// Lakukan redirect
header("Location: " . $url_tujuan);
exit();
?>
