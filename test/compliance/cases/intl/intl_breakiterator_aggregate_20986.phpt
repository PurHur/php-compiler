--TEST--
IntlBreakIterator implements IteratorAggregate; foreach yields boundaries (#20986)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlBreakIterator IteratorAggregate withheld until extension_loaded(\'intl\') (#19670/#20986)';
}
?>
--FILE--
<?php
$bi = IntlBreakIterator::createCharacterInstance('en_US');
$bi->setText('ab');
echo 'agg=', $bi instanceof IteratorAggregate ? 'yes' : 'no', "\n";
echo 'getIterator=', method_exists($bi, 'getIterator') ? 'yes' : 'no', "\n";
$it = $bi->getIterator();
echo 'it_Iterator=', $it instanceof Iterator ? 'yes' : 'no', "\n";
$vals = [];
foreach ($bi as $k => $v) {
    $vals[] = $k . ':' . $v;
}
echo 'foreach=', implode(',', $vals), "\n";
?>
--EXPECT--
agg=yes
getIterator=yes
it_Iterator=yes
foreach=0:0,1:1,2:2
