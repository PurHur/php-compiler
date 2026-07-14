<?php

declare(strict_types=1);

// Repro #18912 — default profile null coerces to "" (ext/standard/string.c, url.c).
foreach (['bin2hex', 'urlencode', 'rawurlencode'] as $fn) {
    echo $fn.'='.var_export($fn(null), true)."\n";
}
