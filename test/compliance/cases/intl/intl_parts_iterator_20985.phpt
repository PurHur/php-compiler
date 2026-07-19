--TEST--
IntlPartsIterator implements Iterator (#20985)
--SKIPIF--
<?php
if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance(basename(__FILE__))) {
    echo 'skip IntlPartsIterator withheld until extension_loaded(\'intl\') (#19670/#20985)';
}
?>
--FILE--
<?php
$bi = IntlBreakIterator::createWordInstance('en_US');
$bi->setText('Hello world');
$p = $bi->getPartsIterator();
echo 'class=', get_class($p), "\n";
echo 'Iterator=', $p instanceof Iterator ? 'yes' : 'no', "\n";
$parts = [];
foreach ($p as $v) {
    $parts[] = $v;
}
echo 'hasHello=', in_array('Hello', $parts, true) ? '1' : '0', "\n";
echo 'hasWorld=', in_array('world', $parts, true) ? '1' : '0', "\n";
?>
--EXPECT--
class=IntlPartsIterator
Iterator=yes
hasHello=1
hasWorld=1
