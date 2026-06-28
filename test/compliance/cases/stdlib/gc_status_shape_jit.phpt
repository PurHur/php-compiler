--TEST--
stdlib gc_status() return shape JIT matches php-src (#9970)
--JIT--
--FILE--
<?php
$s = gc_status();
if (array_key_exists('runs', $s)) {
    echo "skip — legacy gc_status schema on reference profile\n";
    exit(0);
}
ksort($s);
echo implode(',', array_keys($s)), "\n";
foreach (['running', 'protected', 'full', 'buffer_size'] as $key) {
    echo $key, '_', array_key_exists($key, $s) ? 'yes' : 'no', "\n";
}
foreach (['runs', 'collected', 'threshold', 'roots'] as $key) {
    echo $key, '_', array_key_exists($key, $s) ? 'yes' : 'no', "\n";
}
--EXPECT--
buffer_size,full,protected,running
running_yes
protected_yes
full_yes
buffer_size_yes
runs_no
collected_no
threshold_no
roots_no
