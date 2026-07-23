--TEST--
IntlDateFormatter::PATTERN absent on PROFILE=8.2 (#22623)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    die('skip intl OOP withheld until extension_loaded(\'intl\')');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
echo 'defined=', defined('IntlDateFormatter::PATTERN') ? '1' : '0', "\n";
?>
--EXPECT--
defined=0
