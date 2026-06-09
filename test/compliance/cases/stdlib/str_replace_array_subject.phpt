--TEST--
stdlib str_replace() / str_ireplace() array $subject (issue #4056)
--FILE--
<?php
$subject = ['a1', 'b2'];
echo json_encode(str_replace('1', 'X', $subject)), "\n";
echo json_encode(str_ireplace('A', 'b', ['xA', 'yb'])), "\n";
$assoc = ['k' => 'x9', 1 => 'y8'];
$r = str_replace('9', 'Z', $assoc);
echo $r['k'], "\n";
echo $r[1], "\n";
--EXPECT--
["aX","b2"]
["xb","yb"]
xZ
y8
