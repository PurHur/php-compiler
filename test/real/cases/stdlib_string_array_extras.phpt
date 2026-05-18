--TEST--
stdlib string and array extras together
--FILE--
<?php
$parts = str_split(str_pad(strrev('cba'), 6, '-'), 2);
echo count($parts), "\n";
echo $parts[0], '|', $parts[1], '|', $parts[2], "\n";
$nums = array_unique(array(3, 1, 3, 2, 1));
echo count($nums), "\n";
--EXPECT--
3
ab|c-|--
3
