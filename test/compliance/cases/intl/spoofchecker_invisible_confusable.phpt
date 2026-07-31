--TEST--
Spoofchecker::areConfusable after INVISIBLE checks warns U_INVALID_STATE_ERROR (#25209)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip Spoofchecker withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
error_reporting(E_ALL);
$warned = '';
set_error_handler(static function (int $n, string $s) use (&$warned): bool {
    $warned = $s;
    return true;
});
$s = new Spoofchecker();
$s->setChecks(Spoofchecker::INVISIBLE);
$r = $s->areConfusable('paypal', 'paypa1');
echo 'ret=', $r ? '1' : '0', "\n";
echo 'warn=', $warned, "\n";
echo 'intl=', intl_get_error_code(), "\n";
?>
--EXPECT--
ret=1
warn=Spoofchecker::areConfusable(): (27) U_INVALID_STATE_ERROR
intl=0
