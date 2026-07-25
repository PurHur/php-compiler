<?php
declare(strict_types=1);

// #22253 — APCu mutation / CAS / SMA surface after #6574
$need = [
    'apcu_add', 'apcu_inc', 'apcu_dec', 'apcu_cas',
    'apcu_entry', 'apcu_sma_info', 'apcu_key_info', 'apcu_enabled',
];
foreach ($need as $f) {
    echo $f, '=', function_exists($f) ? '1' : '0', "\n";
}

apcu_clear_cache();
echo 'enabled=', apcu_enabled() ? '1' : '0', "\n";

echo 'add1=', apcu_add('k', 10) ? '1' : '0', "\n";
echo 'add2=', apcu_add('k', 20) ? '1' : '0', "\n";
echo 'fetch=', var_export(apcu_fetch('k'), true), "\n";

$ok = false;
echo 'inc=', var_export(apcu_inc('k', 3, $ok), true), ' success=', $ok ? '1' : '0', "\n";
echo 'dec=', var_export(apcu_dec('k', 1, $ok), true), ' success=', $ok ? '1' : '0', "\n";
echo 'cas_fail=', apcu_cas('k', 999, 1) ? '1' : '0', "\n";
echo 'cas_ok=', apcu_cas('k', 12, 100) ? '1' : '0', "\n";
echo 'after_cas=', var_export(apcu_fetch('k'), true), "\n";

apcu_delete('missing_entry');
$v = apcu_entry('missing_entry', static function (string $key): int {
    return 7;
});
echo 'entry=', var_export($v, true), "\n";
echo 'entry2=', var_export(apcu_entry('missing_entry', static function (): int {
    return 99;
}), true), "\n";

$sma = apcu_sma_info(true);
echo 'sma_num=', isset($sma['num_seg']) ? '1' : '0', "\n";
$ki = apcu_key_info('missing_entry');
echo 'key_info=', is_array($ki) && isset($ki['ttl']) ? '1' : '0', "\n";
echo 'key_miss=', var_export(apcu_key_info('nope'), true), "\n";
