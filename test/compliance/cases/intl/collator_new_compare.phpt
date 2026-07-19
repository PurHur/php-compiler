--TEST--
Collator new/compare/getSortKey + collator_compare (#20753)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip Collator withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$c = new Collator('en_US');
echo 'ab=', $c->compare('a', 'b'), "\n";
echo 'ba=', $c->compare('b', 'a'), "\n";
echo 'aa=', $c->compare('a', 'a'), "\n";
echo 'fn=', (int) function_exists('collator_compare'), "\n";
echo 'proc=', collator_compare($c, 'a', 'b'), "\n";
$sk = $c->getSortKey('abc');
echo 'sortkey=', (\is_string($sk) && strlen($sk) > 0) ? '1' : '0', "\n";
?>
--EXPECT--
ab=-1
ba=1
aa=0
fn=1
proc=-1
sortkey=1
