--TEST--
stdlib preg_replace_callback() PREG_OFFSET_CAPTURE + PREG_UNMATCHED_AS_NULL (#19638)
--FILE--
<?php
$c = 0;
echo preg_replace_callback(
    '/a/',
    function ($m) {
        return is_array($m[0]) ? $m[0][0].'@'.$m[0][1] : $m[0];
    },
    'xa',
    -1,
    $c,
    PREG_OFFSET_CAPTURE
), "|$c\n";

$c = 0;
echo preg_replace_callback(
    '/(a)|(b)/',
    function ($m) {
        return ($m[2] === null ? 'N' : 'Y').($m[1] ?? '');
    },
    'a',
    -1,
    $c,
    PREG_UNMATCHED_AS_NULL
), "|$c\n";

$c = 0;
echo preg_replace_callback('/a/', fn ($m) => 'A', 'a', -1, $c, 0), "|$c\n";
--EXPECT--
xa@1|1
Na|1
A|1
