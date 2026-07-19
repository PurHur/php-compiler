--TEST--
Collator sort/getSortKey/strength/attribute surface (#20717)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip Collator withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
$r = new ReflectionClass('Collator');
foreach (['sort', 'sortWithSortKeys', 'getSortKey', 'getStrength', 'setStrength', 'getAttribute', 'setAttribute', 'getLocale', 'getErrorCode', 'getErrorMessage'] as $m) {
    echo $m, '=', $r->hasMethod($m) ? 'yes' : 'MISSING', "\n";
}
foreach (['FRENCH_COLLATION', 'STRENGTH', 'NUMERIC_COLLATION', 'ALTERNATE_HANDLING'] as $c) {
    echo $c, '=', $r->hasConstant($c) ? 'yes' : 'MISSING', "\n";
}
$col = Collator::create('en_US');
echo 'strength_default=', (int) $col->getStrength(), "\n";
$col->setStrength(Collator::PRIMARY);
echo 'strength_primary=', (int) $col->getStrength(), "\n";
echo 'attr_strength=', (int) $col->getAttribute(Collator::STRENGTH), "\n";
echo 'Aa_primary=', (int) (0 === $col->compare('A', 'a')), "\n";
$col->setStrength(Collator::TERTIARY);
echo 'Aa_tertiary=', (int) (0 !== $col->compare('A', 'a')), "\n";
$col->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
echo 'numeric_on=', (int) (Collator::ON === $col->getAttribute(Collator::NUMERIC_COLLATION)), "\n";
$arr = ['c', 'a', 'b'];
$col->sort($arr);
echo 'sort=', implode(',', $arr), ' keys=', implode(',', array_keys($arr)), "\n";
$nums = ['10', '2', '1'];
$col->sortWithSortKeys($nums);
echo 'sortkeys=', implode(',', $nums), "\n";
$key = $col->getSortKey('abc');
echo 'sortkey_nonempty=', (int) (is_string($key) && strlen($key) > 0), "\n";
echo 'locale_valid=', $col->getLocale(1), "\n";
echo 'err=', (int) $col->getErrorCode(), ' msg=', $col->getErrorMessage(), "\n";
?>
--EXPECT--
sort=yes
sortWithSortKeys=yes
getSortKey=yes
getStrength=yes
setStrength=yes
getAttribute=yes
setAttribute=yes
getLocale=yes
getErrorCode=yes
getErrorMessage=yes
FRENCH_COLLATION=yes
STRENGTH=yes
NUMERIC_COLLATION=yes
ALTERNATE_HANDLING=yes
strength_default=2
strength_primary=0
attr_strength=0
Aa_primary=1
Aa_tertiary=1
numeric_on=1
sort=a,b,c keys=0,1,2
sortkeys=1,2,10
sortkey_nonempty=1
locale_valid=en_US
err=0 msg=U_ZERO_ERROR
