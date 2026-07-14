--TEST--
stdlib proc_open(null) — coerces to empty command, returns process resource (#18901, ext/standard/proc_open.c)
--FILE--
<?php
$pipes = [];
$result = proc_open(null, [], $pipes);
echo 'is_resource=', (int) is_resource($result), "\n";
if (is_resource($result)) {
    proc_close($result);
}
?>
--EXPECT--
is_resource=1
