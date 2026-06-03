--TEST--
stdlib preg_replace() array $subject (issue #4055)
--FILE--
<?php
$subject = ['a1', 'b2'];
$result = preg_replace('/\d/', 'X', $subject);
echo $result[0], "\n";
echo $result[1], "\n";
$assoc = ['k' => 'x9', 1 => 'y8'];
$r = preg_replace('/\d/', 'Z', $assoc);
echo $r['k'], "\n";
echo $r[1], "\n";
--EXPECT--
aX
bX
xZ
yZ
