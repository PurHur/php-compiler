--TEST--
stdlib apcu_add/inc/dec/cas/entry/sma_info/key_info/enabled (#22253)
--FILE--
<?php
declare(strict_types=1);
foreach ([
    'apcu_add', 'apcu_inc', 'apcu_dec', 'apcu_cas',
    'apcu_entry', 'apcu_sma_info', 'apcu_key_info', 'apcu_enabled',
] as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}
apcu_clear_cache();
echo apcu_enabled() ? "enabled\n" : "disabled\n";
var_export(apcu_add('n', 5));
echo "\n";
var_export(apcu_add('n', 9));
echo "\n";
$ok = false;
var_export(apcu_inc('n', 2, $ok));
echo "\n";
var_export($ok);
echo "\n";
var_export(apcu_dec('n', 1, $ok));
echo "\n";
var_export(apcu_cas('n', 6, 50));
echo "\n";
var_export(apcu_fetch('n'));
echo "\n";
apcu_delete('e');
var_export(apcu_entry('e', static function (string $k): string {
    return 'gen:'.$k;
}));
echo "\n";
var_export(apcu_entry('e', static function (): string {
    return 'nope';
}));
echo "\n";
$sma = apcu_sma_info(true);
echo isset($sma['num_seg'], $sma['seg_size'], $sma['avail_mem']) ? "sma\n" : "no-sma\n";
$ki = apcu_key_info('e');
echo is_array($ki) && array_key_exists('ttl', $ki) ? "keyinfo\n" : "no-keyinfo\n";
var_export(apcu_key_info('absent'));
echo "\n";
?>
--EXPECT--
apcu_add=1
apcu_inc=1
apcu_dec=1
apcu_cas=1
apcu_entry=1
apcu_sma_info=1
apcu_key_info=1
apcu_enabled=1
enabled
true
false
7
true
6
true
50
'gen:e'
'gen:e'
sma
keyinfo
NULL
