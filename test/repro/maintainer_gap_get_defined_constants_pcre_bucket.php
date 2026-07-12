<?php

declare(strict_types=1);

$c = get_defined_constants(true);
if (!isset($c['pcre']['PREG_PATTERN_ORDER'])) {
    echo "pcre_missing\n";
    exit(0);
}
if (isset($c['standard']['PREG_PATTERN_ORDER'])) {
    echo "preg_in_standard\n";
    exit(0);
}
echo "ok\n";
