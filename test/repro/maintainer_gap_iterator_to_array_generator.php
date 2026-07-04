<?php

declare(strict_types=1);

$gen = function (): Generator {
    yield 1;
    yield 2;
};

$fromClosure = (function (Generator $g): array {
    return iterator_to_array($g);
})($gen());

$topLevel = iterator_to_array($gen());

if (!\is_array($fromClosure) || [1, 2] !== $fromClosure) {
    echo 'fail: from_closure=', var_export($fromClosure, true), "\n";
    exit(1);
}
if (!\is_array($topLevel) || [1, 2] !== $topLevel) {
    echo 'fail: top_level=', var_export($topLevel, true), "\n";
    exit(1);
}

echo "ok\n";
