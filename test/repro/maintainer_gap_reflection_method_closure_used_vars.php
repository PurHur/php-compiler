<?php

declare(strict_types=1);

$rm = new ReflectionMethod('DateTime', 'format');
if (!method_exists($rm, 'getClosureUsedVariables')) {
    fwrite(STDERR, "FAIL: getClosureUsedVariables missing on ReflectionMethod\n");
    exit(1);
}

$used = $rm->getClosureUsedVariables();
if (!is_array($used) || [] !== $used) {
    fwrite(STDERR, "FAIL: expected empty array for ordinary method, got ".var_export($used, true)."\n");
    exit(1);
}

echo "ok\n";
