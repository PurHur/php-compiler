--TEST--
stdlib MemoryUsage enum for memory_get_usage()/memory_get_peak_usage() (#7247)
--FILE--
<?php
var_export(enum_exists('MemoryUsage', false));
echo "\n";
var_export(MemoryUsage::Default->name);
echo "\n";
var_export(MemoryUsage::RealUsage->value);
echo "\n";
$default = memory_get_usage(MemoryUsage::Default);
$real = memory_get_usage(MemoryUsage::RealUsage);
$legacy = memory_get_usage(true);
echo ($default > 0) ? "default_ok\n" : "default_bad\n";
echo ($real > 0) ? "real_ok\n" : "real_bad\n";
echo ($legacy > 0) ? "legacy_ok\n" : "legacy_bad\n";
$peak = memory_get_peak_usage(MemoryUsage::RealUsage);
echo ($peak > 0) ? "peak_ok\n" : "peak_bad\n";
enum Es: string { case B = 'hi'; }
try {
    memory_get_usage(Es::B);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
'Default'
1
default_ok
real_ok
legacy_ok
peak_ok
memory_get_usage(): Argument #1 ($usage) must be of type MemoryUsage|bool, Es given
