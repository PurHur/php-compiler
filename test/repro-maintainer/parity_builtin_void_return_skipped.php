<?php

declare(strict_types=1);

// Maintainer repro: builtins must validate even when return is discarded (#5896, #5900).

try {
    strip_tags([]);
    echo "strip_tags_after\n";
} catch (Throwable $t) {
    echo 'strip_tags: ', get_class($t), ': ', $t->getMessage(), "\n";
}

$fp = fopen('php://memory', 'r+');
try {
    fputcsv($fp, [new stdClass()]);
    echo "fputcsv_after\n";
} catch (Throwable $t) {
    echo 'fputcsv: ', get_class($t), ': ', $t->getMessage(), "\n";
}
fclose($fp);

try {
    md5([]);
    echo "md5_after\n";
} catch (Throwable $t) {
    echo 'md5: ', get_class($t), ': ', $t->getMessage(), "\n";
}
