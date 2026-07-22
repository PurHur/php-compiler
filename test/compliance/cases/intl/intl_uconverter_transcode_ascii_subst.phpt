--TEST--
UConverter::transcode() UTF-8→ASCII substitutes unmappable (#21978, ext/intl/converter/converter.c)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip UConverter withheld until extension_loaded(\'intl\') (#19670/#21978)';
}
?>
--FILE--
<?php
declare(strict_types=1);

foreach (["café", "\xE2\x82\xAC", 'abc'] as $s) {
    $r = UConverter::transcode($s, 'ASCII', 'UTF-8');
    echo bin2hex($s), ' => ', (false === $r ? 'false' : bin2hex($r)), "\n";
}
$u = new UConverter('ASCII', 'UTF-8');
$c = $u->convert('café');
echo 'convert=', (false === $c ? 'false' : bin2hex($c)), "\n";
$ok = UConverter::transcode('café', 'ISO-8859-1', 'UTF-8');
echo 'latin1=', bin2hex($ok), "\n";
?>
--EXPECT--
636166c3a9 => 6361661a
e282ac => 1a
616263 => 616263
convert=6361661a
latin1=636166e9
