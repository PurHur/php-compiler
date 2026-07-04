<?php
// Issue #15917 — negative $decimals ignored like 0 on Zend 8.2 (php-src ext/standard/number_format.c).
echo number_format(1.5, -1), "\n";
echo number_format(1234.5678, -1), "\n";
