--TEST--
stdlib MemoryUsage phantom absent; memory_get_* bool only (#28411, re-#7247)
--FILE--
<?php
var_export(enum_exists('MemoryUsage', false));
echo "\n";
$default = memory_get_usage(false);
$real = memory_get_usage(true);
$legacy = memory_get_usage(true);
echo ($default > 0) ? "default_ok\n" : "default_bad\n";
echo ($real > 0) ? "real_ok\n" : "real_bad\n";
echo ($legacy > 0) ? "legacy_ok\n" : "legacy_bad\n";
$peak = memory_get_peak_usage(true);
echo ($peak > 0) ? "peak_ok\n" : "peak_bad\n";
enum Es: string { case B = 'hi'; }
try {
    memory_get_usage(Es::B);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
false
default_ok
real_ok
legacy_ok
peak_ok
memory_get_usage(): Argument #1 ($real_usage) must be of type bool, Es given
