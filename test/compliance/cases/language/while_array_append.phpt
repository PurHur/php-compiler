--TEST--
language: while loop body array append ($arr[] = value, #10702)
--FILE--
<?php
$entries = [];
$i = 0;
while ($i < 3) {
    $entries[] = 'x';
    $i++;
}
echo count($entries), "\n";
echo $entries[0], $entries[1], $entries[2], "\n";
--EXPECT--
3
xxx
