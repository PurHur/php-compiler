--TEST--
collator_sort/asort/sort_with_sort_keys procedurals (#20838)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip Collator withheld until extension_loaded(\'intl\') (#19670)';
}
?>
--FILE--
<?php
foreach (['collator_sort', 'collator_asort', 'collator_sort_with_sort_keys', 'collator_compare'] as $f) {
    echo $f, '=', (int) function_exists($f), "\n";
}
$col = Collator::create('en_US');
$arr = ['c', 'a', 'b'];
collator_sort($col, $arr);
echo 'sort=', implode(',', $arr), "\n";
$assoc = ['x' => 'c', 'y' => 'a', 'z' => 'b'];
collator_asort($col, $assoc);
echo 'asort=', implode(',', $assoc), ' keys=', implode(',', array_keys($assoc)), "\n";
$nums = ['10', '2', '1'];
$col->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
collator_sort_with_sort_keys($col, $nums);
echo 'sortkeys=', implode(',', $nums), "\n";
?>
--EXPECT--
collator_sort=1
collator_asort=1
collator_sort_with_sort_keys=1
collator_compare=1
sort=a,b,c
asort=a,b,c keys=y,z,x
sortkeys=1,2,10
