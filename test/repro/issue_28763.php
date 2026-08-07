<?php
/**
 * ini_parse_quantity legacy leading-zero octal (#28763).
 * php-src: Zend/zend_ini.c — zend_ini_parse_quantity
 */
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
