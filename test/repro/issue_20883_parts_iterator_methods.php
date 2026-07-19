<?php
$bi = IntlBreakIterator::createWordInstance('en_US');
$bi->setText('Hello, world!');
$pi = $bi->getPartsIterator();
echo 'class=', get_class($pi), "\n";
foreach (['getBreakIterator', 'getRuleStatus', 'getRuleStatusVec'] as $m) {
    echo $m, '=', method_exists($pi, $m) ? 'yes' : 'no', "\n";
}
echo 'rbbi_status=', method_exists($bi, 'getRuleStatus') ? 'yes' : 'no', "\n";
echo 'rbbi_vec=', method_exists($bi, 'getRuleStatusVec') ? 'yes' : 'no', "\n";
$owner = $pi->getBreakIterator();
echo 'owner=', get_class($owner), ' same=', ($owner === $bi ? 'yes' : 'no'), "\n";
$pi->rewind();
while ($pi->valid()) {
    echo 'part=', json_encode($pi->current()), ' status=', $pi->getRuleStatus(), "\n";
    $pi->next();
}
echo 'KEY_SEQUENTIAL=', IntlPartsIterator::KEY_SEQUENTIAL, "\n";
