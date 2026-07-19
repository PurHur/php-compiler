--TEST--
Collator __construct / compare / collator_compare (#20753)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip Collator withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
echo 'collator_compare=', (int) function_exists('collator_compare'), "\n";
$c = new Collator('en_US');
echo 'cmp_ab=', (int) ($c->compare('a', 'b') < 0), "\n";
echo 'cmp_ba=', (int) ($c->compare('b', 'a') > 0), "\n";
echo 'cmp_aa=', (int) (0 === $c->compare('a', 'a')), "\n";
$sk = $c->getSortKey('abc');
echo 'sortkey=', (int) (is_string($sk) && '' !== $sk), "\n";
echo 'proc_ab=', (int) (collator_compare($c, 'a', 'b') < 0), "\n";
$c2 = Collator::create('en_US');
echo 'create_ab=', (int) ($c2->compare('a', 'b') < 0), "\n";
?>
--EXPECT--
collator_compare=1
cmp_ab=1
cmp_ba=1
cmp_aa=1
sortkey=1
proc_ab=1
create_ab=1
