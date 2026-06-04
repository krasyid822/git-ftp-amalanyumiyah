<?php
// test_run.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$dataFile = __DIR__ . '/data_amalan.json';
require_once __DIR__ . '/index.php';

echo "=== TESTING getAyyamulBidhInfoFromClass ===\n";
try {
    $date = new DateTime('2026-06-04');
    $info = getAyyamulBidhInfoFromClass($date);
    echo "SUCCESS!\n";
    print_r($info);
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
