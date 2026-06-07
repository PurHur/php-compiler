--TEST--
stdlib hrtime() native VM without host ext/ffi (#7315)
--SKIPIF--
<?php
if (PHP_OS_FAMILY !== 'Linux' || !is_readable('/proc/uptime')) {
    echo "skip\n";
}
?>
--FILE--
<?php
$a = hrtime(true);
$b = hrtime(true);
echo $b >= $a ? "mono\n" : "bad\n";
$pair = hrtime();
echo count($pair) === 2 && ($pair[0] > 0 || $pair[1] > 0) ? "pair\n" : "bad\n";
--EXPECT--
mono
pair
