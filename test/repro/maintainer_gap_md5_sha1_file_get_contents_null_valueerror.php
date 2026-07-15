<?php

// #19146 — Z_PARAM_PATH null→"" then ValueError on PHP 8.4 forward profile (ext/standard/md5.c, file.c).

$expected = 'Path cannot be empty';
$fail = 0;

foreach (['md5_file', 'sha1_file', 'file_get_contents'] as $fn) {
    try {
        $fn(null);
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
