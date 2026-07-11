--TEST--
AOT ArrayIterator() no-arg constructor (#11792, spl_array.c)
--FILE--
<?php
declare(strict_types=1);

$it = new ArrayIterator();
$it->append(1);
echo count($it) === 1 ? 'ok' : 'fail';
echo "\n";
--EXPECT--
ok
