<?php

declare(strict_types=1);

// Maintainer repro for #4932 — fpassthru() must return bytes read (php-src php_stream_passthru).
$path = 'test/compliance/cases/stdlib/fpassthru_return_bytes_fixture/two.bin';
$h = fopen($path, 'r');
$n = fpassthru($h);
fclose($h);
var_export($n);
echo "\n";
