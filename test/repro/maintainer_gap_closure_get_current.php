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

$outside = Closure::getCurrent();
if (null !== $outside) {
    echo "fail: top-level expected null\n";
    exit(1);
}
echo "ok outside=null\n";
