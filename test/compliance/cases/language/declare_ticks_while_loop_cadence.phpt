--TEST--
declare(ticks=1) file-level + while matches Zend statement cadence (#25621)
--FILE--
<?php
declare(ticks=1);
$n = 0;
register_tick_function(function () use (&$n) {
    $n++;
});
$i = 0;
while ($i < 3) { $i++; }
echo "n=$n\n";
$n = 0;
$i = 0;
while ($i < 0) { $i++; }
echo "never=$n\n";
--EXPECT--
n=6
never=3
