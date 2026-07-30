<?php

declare(strict_types=1);

$r = ini_get_all(details: true);
echo is_array($r) && isset($r['memory_limit']) ? "named_details_ok\n" : "named_details_bad\n";

$flatNamed = ini_get_all(extension: null, details: false);
echo isset($flatNamed['memory_limit']) && \is_string($flatNamed['memory_limit'])
    ? "named_extension_null_flat_ok\n"
    : "named_extension_null_flat_bad\n";
