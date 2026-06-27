--TEST--
stdlib gc_status() Zend 8.2 reference profile legacy keys (issue #12790)
--FILE--
<?php
$s = gc_status();
if (!array_key_exists('runs', $s)) {
    echo "skip — requires reference profile legacy gc_status schema\n";
    exit(0);
}
$s = gc_status();
foreach (['runs', 'collected', 'threshold', 'roots'] as $key) {
    echo $key, '=', array_key_exists($key, $s) ? 'yes' : 'no', "\n";
}
foreach (['running', 'protected', 'full', 'buffer_size'] as $key) {
    echo $key, '=', array_key_exists($key, $s) ? 'yes' : 'no', "\n";
}
--EXPECT--
runs=yes
collected=yes
threshold=yes
roots=yes
running=no
protected=no
full=no
buffer_size=no
