<?php

declare(strict_types=1);

// Repro #16885 — mbstring encoding: named parameter (ext/mbstring/mbstring.stub.php).
$substr = mb_substr('hello', 0, 2, encoding: 'UTF-8');
$strim = mb_strimwidth('hello', 0, 3, '..', encoding: 'UTF-8');
$strcut = mb_strcut('hello', 0, 3, encoding: 'UTF-8');
$stripos = mb_stripos('Hello', 'll', encoding: 'UTF-8');

if ('he' !== $substr || 'h..' !== $strim || 'hel' !== $strcut || 2 !== $stripos) {
    echo "fail substr=$substr strim=$strim strcut=$strcut stripos=$stripos\n";
    exit(1);
}

echo "ok\n";
