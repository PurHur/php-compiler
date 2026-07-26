--TEST--
declare(ticks=1) file-level + for-loop matches Zend statement cadence (#23486)
--FILE--
<?php
declare(ticks=1);
$n = 0;
register_tick_function(function () use (&$n) {
    $n++;
});
for ($i = 0; $i < 5; $i++) {
    $x = $i;
}
echo "n=$n\n";
--EXPECT--
n=7
