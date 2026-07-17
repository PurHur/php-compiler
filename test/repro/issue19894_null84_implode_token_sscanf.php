<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
$cases = [
    'implode' => static fn () => implode(null, ['a', 'b']),
    'join' => static fn () => join(null, ['a']),
    'token_get_all' => static fn () => token_get_all(null),
    'sscanf' => static fn () => sscanf(null, '%s'),
    'strlen' => static fn () => strlen(null),
];
foreach ($cases as $n => $fn) {
    try {
        $fn();
        echo "$n => VALUE\n";
    } catch (Throwable $e) {
        echo "$n => ".get_class($e)."\n";
    }
}
