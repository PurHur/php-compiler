<?php
// #33122 — thin AOT fpassthru via libc FILE* force
$path = 'test/compliance/cases/stdlib/fpassthru_return_bytes_fixture/two.bin';
$h = fopen($path, 'rb');
$n = fpassthru($h);
fclose($h);
echo "\n", var_export($n, true), "\n";
