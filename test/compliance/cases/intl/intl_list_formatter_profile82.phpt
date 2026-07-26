--TEST--
IntlListFormatter withheld on PROFILE=8.2 (#23229)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    die('skip need host php-intl to distinguish profile gate from extension gate');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
declare(strict_types=1);
echo 'class=', class_exists('IntlListFormatter') ? '1' : '0', "\n";
?>
--EXPECT--
class=0
