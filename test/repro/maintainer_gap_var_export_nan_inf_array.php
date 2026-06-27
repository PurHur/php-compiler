<?php

declare(strict_types=1);

$s = var_export([NAN, INF], true);
if (null === $s || !str_contains($s, 'NAN') || !str_contains($s, 'INF')) {
    echo "fail\n";
    exit(1);
}

echo "ok\n";
