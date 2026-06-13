--TEST--
stdlib preg_replace_callback_array() multi-pattern callback replace (#3568)
--FILE--
<?php
$out = preg_replace_callback_array(
    ['/\d+/' => fn(array $m): string => '[' . $m[0] . ']'],
    'a1b2'
);
echo $out, "\n";

$out2 = preg_replace_callback_array(
    [
        '/a/' => fn(array $m): string => 'b',
        '/b/' => fn(array $m): string => 'c',
    ],
    'a'
);
echo $out2, "\n";
?>
--EXPECT--
a[1]b[2]
c
