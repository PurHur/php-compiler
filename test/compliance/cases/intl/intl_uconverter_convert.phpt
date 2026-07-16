--TEST--
UConverter construct/convert ISO-8859-1 ↔ UTF-8 (#6171)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip UConverter withheld until extension_loaded(\'intl\') (#19670/#6171)';
}
?>
--FILE--
<?php
echo 'uconverter=', (int) class_exists('UConverter', false), "\n";
$u = new UConverter('ISO-8859-1', 'UTF-8');
$latin1 = $u->convert("\xC3\xA9");
echo 'to_latin1=', bin2hex($latin1), "\n";
echo 'err=', $u->getErrorCode(), ' ', $u->getErrorMessage(), "\n";
$back = $u->convert($latin1, true);
echo 'roundtrip=', bin2hex($back), "\n";
$bad = new UConverter('not-a-real-encoding', 'UTF-8');
echo 'bad_open=', $bad->getErrorCode(), "\n";
echo 'bad_convert=', var_export($bad->convert('abc'), true), "\n";
echo 'bad_state=', $bad->getErrorCode(), "\n";
?>
--EXPECT--
uconverter=1
to_latin1=e9
err=0 U_ZERO_ERROR
roundtrip=c3a9
bad_open=4
bad_convert=false
bad_state=27
