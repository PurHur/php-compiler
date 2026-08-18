--TEST--
AOT json_encode() returns false for INF/NAN (#32326, #3606, php_json_encode_double)
--FILE--
<?php
$r = json_encode(INF);
echo $r === false ? 'false' : (string) $r, "\n";
echo json_last_error() === 7 ? '7' : 'n', "\n";
$n = acos(2);
$r = json_encode($n);
echo $r === false ? 'false' : (string) $r, "\n";
echo json_last_error() === 7 ? '7' : 'n', "\n";
echo json_encode(1.5), "\n";
echo json_encode(NAN, JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
echo json_last_error() === 7 ? '7' : 'n', "\n";
--EXPECT--
false
7
false
7
1.5
0
7
