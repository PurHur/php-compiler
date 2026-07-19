<?php
$bi = IntlBreakIterator::createWordInstance('en_US');
$bi->setText('Hello world');
$p = $bi->getPartsIterator();
echo 'class=', get_class($p), "\n";
echo 'Iterator=', $p instanceof Iterator ? 'yes' : 'no', "\n";
$n = 0;
foreach ($p as $v) {
    echo 'v=', $v, "\n";
    if (++$n >= 3) {
        break;
    }
}
