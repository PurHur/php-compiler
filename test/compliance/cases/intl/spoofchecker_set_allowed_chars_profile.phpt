--TEST--
Spoofchecker::setAllowedChars withheld on default/8.2 profile (#23157)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip Spoofchecker withheld until extension_loaded(\'intl\') (#19670/#23157)';
}
?>
--FILE--
<?php
echo 'setAllowedChars=', method_exists(Spoofchecker::class, 'setAllowedChars') ? '1' : '0', "\n";
echo 'getAllowedChars=', method_exists(Spoofchecker::class, 'getAllowedChars') ? '1' : '0', "\n";
echo 'ignore=', defined('Spoofchecker::IGNORE_SPACE') ? '1' : '0', "\n";
?>
--EXPECT--
setAllowedChars=0
getAllowedChars=0
ignore=0
