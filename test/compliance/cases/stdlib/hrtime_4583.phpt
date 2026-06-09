--TEST--
stdlib hrtime() return shapes and && short-circuit probe (#4583, ext/standard/hrtime.c)
--SKIPIF--
<?php
if (PHP_OS_FAMILY !== 'Linux' || !is_readable('/proc/uptime')) {
    echo "skip\n";
}
?>
--FILE--
<?php
try {
    hrtime(1);
    echo "int_ok\n";
} catch (TypeError $e) {
    echo "int_type_error\n";
}
$pair = hrtime();
$ns = hrtime(true);
echo is_array($pair) ? "pair\n" : "bad\n";
echo count($pair) === 2 ? "count2\n" : "bad\n";
echo is_int($ns) && $ns > 0 ? "ns\n" : "bad\n";
--EXPECT--
int_ok
pair
count2
ns
