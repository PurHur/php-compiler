<?php

declare(strict_types=1);

$ok = (function (): string {
    $self = Closure::getCurrent();
    if (!$self instanceof Closure) {
        return 'fail: not Closure';
    }

    return 'ok class=' . $self::class;
})();
echo $ok, "\n";

function maintainer_gap_closure_get_current_fail(): void
{
    Closure::getCurrent();
}

try {
    maintainer_gap_closure_get_current_fail();
    echo "fail: no exception\n";
} catch (\Error $e) {
    echo $e->getMessage(), "\n";
}
