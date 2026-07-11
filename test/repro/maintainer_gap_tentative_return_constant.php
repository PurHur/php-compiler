<?php

declare(strict_types=1);

if (!defined('TENTATIVE_RETURN')) {
    fwrite(STDERR, "FAIL: TENTATIVE_RETURN undefined\n");
    exit(1);
}
if (TENTATIVE_RETURN !== 1) {
    fwrite(STDERR, 'FAIL: TENTATIVE_RETURN='.var_export(TENTATIVE_RETURN, true)."\n");
    exit(1);
}
echo "ok\n";
