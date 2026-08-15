<?php

declare(strict_types=1);

// #31360 — null $mode under strict_types → TypeError; omitted mode / null callback OK.
try {
    array_filter([0, 1, 2], null, null);
    echo "fail null mode\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

$filtered = array_filter([0, 1, 2], null);
echo json_encode($filtered), "\n";

$omitted = array_filter([0, 1, 2]);
echo json_encode($omitted), "\n";
