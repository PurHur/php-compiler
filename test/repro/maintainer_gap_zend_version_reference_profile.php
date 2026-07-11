<?php

declare(strict_types=1);

$v = zend_version();
if (!str_starts_with($v, '4.2.')) {
    echo "fail: zend_version expected 4.2.x on reference profile, got '{$v}'\n";
    exit(1);
}

echo "ok\n";
