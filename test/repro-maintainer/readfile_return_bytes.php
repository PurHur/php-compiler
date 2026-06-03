<?php

declare(strict_types=1);

// Maintainer repro for #4932 — readfile() must return bytes read (php-src php_stream_passthru).
$path = 'test/compliance/cases/stdlib/readfile_return_bytes_fixture/two.bin';
$n = readfile($path);
var_export($n);
echo "\n";
