<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    if (E_DEPRECATED === $errno) {
        echo 'DEP:', $errstr, "\n";
        return true;
    }
    echo "ERR[$errno]: $errstr\n";
    return true;
});
echo substr_replace('abcdef', 'X', null, 1), "\n";
