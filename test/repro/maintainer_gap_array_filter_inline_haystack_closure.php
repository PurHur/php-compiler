<?php
declare(strict_types=1);

$r = array_filter(explode(',', 'a,b'), static fn ($x): bool => true);
if ($r !== ['a', 'b']) {
    echo 'fail inline haystack closure';
    exit(1);
}

echo "ok\n";
