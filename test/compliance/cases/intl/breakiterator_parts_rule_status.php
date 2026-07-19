<?php
$bi = IntlBreakIterator::createWordInstance('en_US');
$bi->setText('Hello, world!');
$pi = $bi->getPartsIterator();
echo 'owner_same=', ($pi->getBreakIterator() === $bi ? '1' : '0'), "\n";
echo 'pi_status_method=', (int) method_exists($pi, 'getRuleStatus'), "\n";
echo 'pi_vec_method=', (int) method_exists($pi, 'getRuleStatusVec'), "\n";
echo 'rbbi_status_method=', (int) method_exists($bi, 'getRuleStatus'), "\n";
echo 'rbbi_vec_method=', (int) method_exists($bi, 'getRuleStatusVec'), "\n";
$pi->rewind();
$out = [];
while ($pi->valid()) {
    $out[] = $pi->current() . ':' . $pi->getRuleStatus();
    $pi->next();
}
echo implode('|', $out), "\n";
$bi->first();
$bi->next();
echo 'rbbi_at_hello=', $bi->getRuleStatus(), "\n";
echo 'rbbi_vec=', json_encode($bi->getRuleStatusVec()), "\n";
echo 'KEY_LEFT=', IntlPartsIterator::KEY_LEFT, "\n";
