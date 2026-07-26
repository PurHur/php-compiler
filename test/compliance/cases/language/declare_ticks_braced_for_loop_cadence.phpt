--TEST--
declare(ticks=1) braced for-loop matches Zend statement cadence (#23486)
--FILE--
<?php
$n = 0;
register_tick_function(function () use (&$n) {
    $n++;
});
declare(ticks=1) {
    for ($i = 0; $i < 5; $i++) {
        $x = $i;
    }
}
echo "n=$n\n";
--EXPECT--
n=6
