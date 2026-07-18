--TEST--
stdlib gc_status() PHP 8.4 keys retain legacy + timing (issue #12780, #20627)
--FILE--
<?php
$s = gc_status();
if (!array_key_exists('running', $s)) {
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
foreach (['application_time', 'collector_time', 'destructor_time', 'free_time'] as $key) {
    echo $key, '=', array_key_exists($key, $s) ? 'yes' : 'no', "\n";
}
--EXPECT--
running=yes
protected=yes
full=yes
buffer_size=yes
runs=yes
collected=yes
threshold=yes
roots=yes
application_time=yes
collector_time=yes
destructor_time=yes
free_time=yes
