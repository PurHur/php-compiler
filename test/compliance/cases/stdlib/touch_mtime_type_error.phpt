--TEST--
stdlib touch() — TypeError when $mtime is not ?int (#4989)
--FILE--
<?php
$f = sys_get_temp_dir() . '/phpc_touch_' . getmypid();
@unlink($f);
@touch($f);
try {
    var_export(touch($f, [time(), time()]));
} catch (TypeError $e) {
    echo 'TypeError', "\n";
    echo $e->getMessage(), "\n";
}
@unlink($f);
--EXPECT--
TypeError
touch(): Argument #2 ($mtime) must be of type ?int, array given
