--TEST--
IntlTimeZone::createTimeZone unknown/empty ID → Etc/Unknown (#25356)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlTimeZone withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$z = IntlTimeZone::createTimeZone('No/Such');
echo 'unknown=', $z->getID(), "\n";
echo 'unknown_err=', intl_get_error_code(), "\n";
echo 'unknown_msg=', intl_get_error_message(), "\n";

$e = IntlTimeZone::createTimeZone('');
echo 'empty=', $e->getID(), "\n";
echo 'empty_err=', intl_get_error_code(), "\n";

$ok = IntlTimeZone::createTimeZone('America/New_York');
echo 'ok=', $ok->getID(), "\n";

$u = IntlTimeZone::getUnknown();
echo 'getUnknown=', $u->getID(), "\n";
echo 'same_as_unknown=', (int) ($z->getID() === $u->getID()), "\n";

$proc = intltz_create_time_zone('No/Such');
echo 'proc=', $proc->getID(), "\n";
?>
--EXPECT--
unknown=Etc/Unknown
unknown_err=0
unknown_msg=U_ZERO_ERROR
empty=Etc/Unknown
empty_err=0
ok=America/New_York
getUnknown=Etc/Unknown
same_as_unknown=1
proc=Etc/Unknown
