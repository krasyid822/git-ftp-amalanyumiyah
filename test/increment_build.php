<?php
// FILE: test/increment_build.php

$dir = dirname(__FILE__);
$file = $dir . '/build_number.txt';

$build = 0;
if (file_exists($file)) {
    $build = (int)trim(file_get_contents($file));
}

$build++;
file_put_contents($file, (string)$build);

echo "Build number incremented to: $build\n";

// Staging and committing the build number change
exec('git add test/build_number.txt');
exec('git commit -m "build: increment build number to ' . $build . '" --allow-empty');
?>
