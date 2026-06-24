--TEST--
stdlib gc_status() return shape JIT matches php-src (#9970)
--JIT--
--FILE--
<?php
$s = gc_status();
ksort($s);
echo implode(',', array_keys($s)), "\n";
foreach (['running', 'protected', 'full', 'buffer_size'] as $key) {
    echo $key, '_', array_key_exists($key, $s) ? 'yes' : 'no', "\n";
}
foreach (['runs', 'collected', 'threshold', 'roots'] as $key) {
    echo $key, '_', array_key_exists($key, $s) ? 'yes' : 'no', "\n";
}
--EXPECT--
collected,roots,runs,threshold
running_no
protected_no
full_no
buffer_size_no
runs_yes
collected_yes
threshold_yes
roots_yes
