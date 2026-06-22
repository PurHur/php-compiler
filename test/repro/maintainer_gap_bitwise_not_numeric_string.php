<?php

declare(strict_types=1);

// Zend: per-byte ~ on string operands (#10537, zend_operators.c).
echo bin2hex(~"5"), "\n";
echo bin2hex(~"0"), "\n";
echo bin2hex(~"255"), "\n";
