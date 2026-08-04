<?php
declare(strict_types=1);

/**
 * Issue #27625: AOT get_class($e) after never-typed (cross-function) throw.
 * Catch select-walk must include RuntimeException before the callee registers it.
 */
function bye(): never
{
    throw new RuntimeException('x');
}

try {
    bye();
} catch (Throwable $e) {
    echo 'class=[', get_class($e), "]\n";
    echo 'msg=[', $e->getMessage(), "]\n";
    echo 'isRT=', $e instanceof RuntimeException ? '1' : '0', "\n";
}
