<?php

declare(strict_types=1);

$p = 0.0;
similar_text('abc', 'abd', $p);
if (!is_float($p) || $p <= 0.0) {
    echo "fail: percent not populated\n";
    exit(1);
}
echo "ok\n";
