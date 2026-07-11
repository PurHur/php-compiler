<?php

declare(strict_types=1);

$encoded = json_encode(-0.0);
if ($encoded !== '-0') {
    echo "encoded={$encoded}\n";
    exit(1);
}
if (json_encode(0.0) !== '0') {
    echo "positive zero broken\n";
    exit(1);
}
echo "ok\n";
