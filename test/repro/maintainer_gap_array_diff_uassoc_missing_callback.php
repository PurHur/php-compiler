<?php

declare(strict_types=1);

try {
    array_diff_uassoc([1 => 'a'], [1 => 'b']);
    fwrite(STDERR, "expected TypeError\n");
    exit(1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

try {
    array_intersect_uassoc([1 => 'a'], [1 => 'b']);
    fwrite(STDERR, "expected TypeError for intersect\n");
    exit(1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
