--TEST--
ini_parse_quantity() legacy leading-zero octal (#28763, Zend/zend_ini.c)
--FILE--
<?php
error_reporting(E_ALL);
$cases = ['010', '0010', '+010', '-010', '08', '09', '0o10', '0x10', '0b10', '077', '078'];
foreach ($cases as $v) {
    error_clear_last();
    $r = @ini_parse_quantity($v);
    $last = error_get_last();
    echo $v, '=', var_export($r, true);
    if (is_array($last) && isset($last['message']) && str_contains((string) $last['message'], 'unknown multiplier')) {
        echo ' WARN:unknown_multiplier';
    }
    echo "\n";
}
?>
--EXPECT--
010=8
0010=8
+010=8
-010=-8
08=0 WARN:unknown_multiplier
09=0 WARN:unknown_multiplier
0o10=8
0x10=16
0b10=2
077=63
078=7 WARN:unknown_multiplier
