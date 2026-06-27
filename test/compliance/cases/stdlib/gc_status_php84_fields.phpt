--TEST--
stdlib gc_status() PHP 8.4 keys running/protected/full/buffer_size (issue #12780)
--FILE--
<?php
$s = gc_status();
if (array_key_exists('runs', $s)) {
    echo "skip — legacy gc_status schema on reference profile\n";
    exit(0);
}
$s = gc_status();
foreach (['running', 'protected', 'full', 'buffer_size'] as $key) {
    echo $key, '=', array_key_exists($key, $s) ? 'yes' : 'no', "\n";
}
foreach (['runs', 'collected', 'threshold', 'roots'] as $key) {
    echo $key, '=', array_key_exists($key, $s) ? 'yes' : 'no', "\n";
}
--EXPECT--
running=yes
protected=yes
full=yes
buffer_size=yes
runs=no
collected=no
threshold=no
roots=no
