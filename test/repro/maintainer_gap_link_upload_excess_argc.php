<?php

/**
 * #30553 — link/upload builtins excess argc → ArgumentCountError (php-src link.c / file.c).
 */
error_reporting(E_ALL);

$cases = [
    'symlink("/a", "/b", "x")',
    'readlink("/a", "x")',
    'linkinfo("/a", "x")',
    'is_uploaded_file("/a", "x")',
    'move_uploaded_file("/a", "/b", "x")',
];
foreach ($cases as $code) {
    try {
        eval($code.';');
        echo "$code => NO_THROW\n";
    } catch (Throwable $e) {
        echo $code, ' => ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
