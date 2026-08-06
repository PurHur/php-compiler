<?php

declare(strict_types=1);

// #28222 — Zend/zend_builtin_functions.stub.php: trigger_error/user_error(): true
foreach (['trigger_error', 'user_error'] as $f) {
    $r = new ReflectionFunction($f);
    $ret = $r->hasReturnType() ? (string) $r->getReturnType() : '<none>';
    if ('true' !== $ret) {
        fwrite(STDERR, "fail: {$f} return={$ret} expected=true\n");
        exit(1);
    }
    $p = $r->getParameters();
    if ('message' !== $p[0]->getName() || 'string' !== (string) $p[0]->getType()) {
        fwrite(STDERR, "fail: {$f} message param drift\n");
        exit(1);
    }
    if ('error_level' !== $p[1]->getName()
        || 'int' !== (string) $p[1]->getType()
        || 1024 !== $p[1]->getDefaultValue()
    ) {
        fwrite(STDERR, "fail: {$f} error_level param drift\n");
        exit(1);
    }
    echo "{$f} ok\n";
}
