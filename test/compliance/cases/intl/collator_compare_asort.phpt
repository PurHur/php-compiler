--TEST--
Collator create/compare/asort ICU subset (#5747)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip Collator withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
echo 'intl_loaded=', (int) extension_loaded('intl'), "\n";
echo 'collator=', (int) class_exists('Collator', false), "\n";
echo 'collator_create=', (int) function_exists('collator_create'), "\n";
$c = Collator::create('en_US');
echo 'cmp_ab=', (int) ($c->compare('a', 'b') < 0), "\n";
echo 'cmp_ba=', (int) ($c->compare('b', 'a') > 0), "\n";
echo 'cmp_aa=', (int) (0 === $c->compare('a', 'a')), "\n";
$arr = ['x' => 'c', 'y' => 'a', 'z' => 'b'];
$c->asort($arr);
echo 'asort=', implode(',', $arr), "\n";
$p = collator_create('en_US');
echo 'proc=', get_class($p), "\n";
?>
--EXPECT--
intl_loaded=1
collator=1
collator_create=1
cmp_ab=1
cmp_ba=1
cmp_aa=1
asort=a,b,c
proc=Collator
