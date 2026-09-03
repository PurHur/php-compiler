<?php
// @differential-repeat: 3 {main} in-place .= must match Zend (#36386 / #36410)
$buf = '';
for ($i = 0; $i < 100; ++$i) {
    $buf .= 'x';
}
$mix = 'ab';
$mix .= 'cd';
$row = '';
for ($i = 0; $i < 5; ++$i) {
    $row .= 'row-'.$i.';';
}
echo strlen($buf), '|', $mix, '|', strlen($row), '|', $row, "\n";
