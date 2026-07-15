<?php

// #19162 — Z_PARAM_PATH null→"" then ValueError on PHP 8.4 forward profile (ext/standard/file.c).

$expected = 'Path cannot be empty';
$fail = 0;
$null = null;

foreach ([
    'fopen' => [$null, 'r'],
    'copy' => [$null, '/tmp/x'],
    'readfile' => [$null],
    'file' => [$null],
] as $fn => $args) {
    try {
        $fn(...$args);
        echo $fn, ": uncaught\n";
        ++$fail;
    } catch (ValueError $e) {
        if ($expected !== $e->getMessage()) {
            echo $fn, ': wrong message: ', $e->getMessage(), "\n";
            ++$fail;
        }
    } catch (TypeError $e) {
        echo $fn, ': TypeError: ', $e->getMessage(), "\n";
        ++$fail;
    }
}

echo 0 === $fail ? "ok\n" : "fail\n";
