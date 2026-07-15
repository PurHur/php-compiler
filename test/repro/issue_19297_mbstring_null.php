<?php
// Repro #19297 — mbstring null string operands under PHP_COMPILER_PROFILE=8.4
$cases = [
    'mb_strlen' => static fn () => mb_strlen(null),
    'mb_substr' => static fn () => mb_substr(null, 0),
    'mb_strpos' => static fn () => mb_strpos(null, 'a'),
    'mb_strtolower' => static fn () => mb_strtolower(null),
    'mb_strtoupper' => static fn () => mb_strtoupper(null),
    'mb_convert_encoding' => static fn () => mb_convert_encoding(null, 'UTF-8'),
];
foreach ($cases as $name => $fn) {
    try {
        $fn();
        echo "$name: uncaught\n";
    } catch (TypeError $e) {
        echo $name.': '.$e->getMessage()."\n";
    }
}
