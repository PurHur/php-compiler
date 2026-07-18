--TEST--
stdlib gc_status() return shape JIT matches php-src (#9970, #20627)
--JIT--
--FILE--
<?php
$s = gc_status();
if (!array_key_exists('running', $s)) {
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
foreach (['application_time', 'collector_time', 'destructor_time', 'free_time'] as $key) {
    echo $key, '_', array_key_exists($key, $s) ? 'yes' : 'no', "\n";
}
--EXPECT--
application_time,buffer_size,collected,collector_time,destructor_time,free_time,full,protected,roots,running,runs,threshold
running_yes
protected_yes
full_yes
buffer_size_yes
runs_yes
collected_yes
threshold_yes
roots_yes
application_time_yes
collector_time_yes
destructor_time_yes
free_time_yes
