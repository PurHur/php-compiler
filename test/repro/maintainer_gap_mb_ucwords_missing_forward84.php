<?php
// Repro #21458 — mb_ucwords() must stay undefined on PROFILE=8.4 (Zend never ships it)
declare(strict_types=1);

if (function_exists('mb_ucwords')) {
    echo "fail: mb_ucwords still registered\n";
    exit(1);
}

echo "exists:no\n";
