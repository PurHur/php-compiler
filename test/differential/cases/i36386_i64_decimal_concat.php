<?php
// @differential-repeat: 3 string.int concat via zend_print_long_to_buf (#36386)
$buf = '';
for ($i = -3; $i < 8; ++$i) {
    $buf .= 'row-'.$i.';';
}
echo strlen($buf), '|', $buf, "\n";
echo 'x'.(-12).'y', '|', 0 . 42, '|', (string) PHP_INT_MIN, "\n";
