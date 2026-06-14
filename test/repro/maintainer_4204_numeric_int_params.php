<?php
/** Issue #4204 — numeric-string int parameters for count_chars/unpack/str_split. */

function run(string $mode, string $len, string $off): void
{
    var_export(count_chars('ab', $mode));
    echo "\n";
    var_dump(unpack('C', 'x', $off));
    var_dump(str_split('hi', $len));
}

run('1', '2', '0');

try {
    unpack('C', 'x', 'abc');
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
