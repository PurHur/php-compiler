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
