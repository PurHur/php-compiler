--TEST--
MessageFormatter {0,number} locale grouping separators (#21959)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip MessageFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
echo MessageFormatter::formatMessage('en_US', '{0,number}', [1234]), "\n";
$f = MessageFormatter::create('en_US', '{0,number}');
echo $f->format([1234]), "\n";
echo MessageFormatter::formatMessage('en_US', '{0,number}', [1234.5]), "\n";
echo MessageFormatter::formatMessage('en_US', '{0,number,integer}', [1234.7]), "\n";
echo MessageFormatter::formatMessage('de_DE', '{0,number}', [1234]), "\n";
$plural = new MessageFormatter('en_US', '{n, plural, one{# item} other{# items}}');
echo $plural->format(['n' => 1234]), "\n";
?>
--EXPECT--
1,234
1,234
1,234.5
1,234
1.234
1,234 items
