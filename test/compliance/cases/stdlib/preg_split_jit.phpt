--TEST--
stdlib preg_split() JIT splits string by pattern (issue #1178)
--FILE--
<?php
$parts = preg_split('#,#', 'a,b,c');
echo count($parts), "\n";
foreach ($parts as $part) {
    echo $part, "\n";
}
$parts2 = preg_split('/\s+/', 'a  b   c');
echo count($parts2), "\n";
foreach ($parts2 as $part) {
    echo $part, "\n";
}
--EXPECT--
3
a
b
c
3
a
b
c
