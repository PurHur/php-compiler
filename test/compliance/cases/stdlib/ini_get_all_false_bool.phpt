--TEST--
stdlib ini_get_all(false) — bool extension operand TypeError (#18555, ext/standard/ini.c)
--FILE--
<?php
declare(strict_types=1);

try {
    ini_get_all(false);
    echo "no_error\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

$all = ini_get_all();
echo isset($all['display_errors']) ? "all_ok\n" : "all_fail\n";
$flat = ini_get_all(null, false);
echo is_string($flat['display_errors']) ? "flat_ok\n" : "flat_fail\n";
?>
--EXPECT--
ini_get_all(): Argument #1 ($extension) must be of type ?string, bool given
all_ok
flat_ok
