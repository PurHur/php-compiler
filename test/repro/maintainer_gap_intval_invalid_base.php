<?php
/**
 * Parity: intval() invalid $base (>36 or <2, except 0 autodetect).
 * Zend: returns 0. VM: bogus parse for base > 36 (issue #10672).
 */
declare(strict_types=1);

echo '37: ', intval('ff', 37), "\n";
echo '1: ', intval('10', 1), "\n";
echo '16: ', intval('ff', 16), "\n";
echo '0: ', intval('0x10', 0), "\n";
echo 'int: ', intval(42, 37), "\n";
