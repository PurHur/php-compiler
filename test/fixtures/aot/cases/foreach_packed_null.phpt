--TEST--
AOT foreach over packed array with null element terminates (#24261)
--FILE--
<?php
$a = [1, null, 3];
$c = 0;
foreach ($a as $v) {
    ++$c;
}
echo $c, "\n";
--EXPECT--
3
