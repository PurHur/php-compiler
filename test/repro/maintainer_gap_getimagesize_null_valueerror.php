<?php

$expected = 'Path must not be empty';

foreach ([null, ''] as $path) {
    try {
        getimagesize($path);
        echo 'fail: getimagesize(', var_export($path, true), ") returned without ValueError\n";
        exit(1);
    } catch (ValueError $e) {
        if ($expected !== $e->getMessage()) {
            echo 'fail: getimagesize(', var_export($path, true), ') got ', $e->getMessage(), "\n";
            exit(1);
        }
    }
}

echo "ok\n";
