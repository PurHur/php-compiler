--TEST--
UConverter::transcode() options to_subst + setSubstChars convert (#25201/#25202, ext/intl/converter)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip UConverter withheld until extension_loaded(\'intl\') (#19670/#25201)';
}
?>
--FILE--
<?php
declare(strict_types=1);

echo 'to_subst=', bin2hex(UConverter::transcode('é', 'ASCII', 'UTF-8', ['to_subst' => '?'])), "\n";
echo 'default=', bin2hex(UConverter::transcode('é', 'ASCII', 'UTF-8')), "\n";
echo 'bad_to_subst=', bin2hex(UConverter::transcode("\x80", 'ASCII', 'UTF-8', ['to_subst' => '?'])), "\n";

$u = new UConverter('ASCII', 'UTF-8');
echo 'convert_default=', bin2hex($u->convert('é')), "\n";
var_export($u->setSubstChars('?'));
echo "\n";
echo 'convert_set=', bin2hex($u->convert('é')), "\n";
?>
--EXPECT--
to_subst=3f
default=1a
bad_to_subst=3f
convert_default=1a
true
convert_set=3f
