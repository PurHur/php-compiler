<?php

declare(strict_types=1);

// php-src-strict: null coerces outside strict_types (#18617); TypeError under declare(strict_types=1) (#18665).
var_export(json_decode(null));
echo "\n";
