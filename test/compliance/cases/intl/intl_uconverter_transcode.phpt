--TEST--
UConverter::transcode() ISO-8859-1 ↔ UTF-8 (#6401, ext/intl/converter/converter.c)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip UConverter withheld until extension_loaded(\'intl\') (#19670/#6401)';
}
?>
--FILE--
<?php
declare(strict_types=1);

echo 'uconverter=', (int) class_exists('UConverter', false), "\n";
$out = UConverter::transcode('café', 'ISO-8859-1', 'UTF-8');
echo 'latin1=', bin2hex($out), "\n";
echo 'match=', var_export($out === "caf\xE9", true), "\n";
$back = UConverter::transcode($out, 'UTF-8', 'ISO-8859-1');
echo 'roundtrip=', bin2hex($back), "\n";

enum Es: string { case B = 'x'; }
try {
    UConverter::transcode(Es::B, 'UTF-8', 'ISO-8859-1');
    echo "enum uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
uconverter=1
latin1=636166e9
match=true
roundtrip=c3a9
UConverter::transcode(): Argument #1 ($str) must be of type string, Es given
