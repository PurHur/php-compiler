--TEST--
MessageFormatter spellout/ordinal/duration/selectordinal (#25227)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip MessageFormatter withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$sel = '{0,selectordinal, one{#st} two{#nd} few{#rd} other{#th}}';
echo (new MessageFormatter('en_US', '{0,spellout}'))->format([42]), "\n";
echo (new MessageFormatter('en_US', '{0,ordinal}'))->format([3]), "\n";
echo (new MessageFormatter('en_US', '{0,duration}'))->format([125]), "\n";
echo (new MessageFormatter('en_US', '{0,duration}'))->format([42]), "\n";
echo (new MessageFormatter('en_US', $sel))->format([1]), "\n";
echo (new MessageFormatter('en_US', $sel))->format([2]), "\n";
echo (new MessageFormatter('en_US', $sel))->format([3]), "\n";
echo (new MessageFormatter('en_US', $sel))->format([4]), "\n";
echo (new MessageFormatter('en_US', $sel))->format([11]), "\n";
echo (new MessageFormatter('en_US', $sel))->format([21]), "\n";
echo MessageFormatter::formatMessage('en_US', '{0,spellout}', [100]), "\n";
?>
--EXPECT--
forty-two
3rd
2:05
42 sec.
1st
2nd
3rd
4th
11th
21st
one hundred
