<?php

declare(strict_types=1);

// php-src-strict (Zend 8.2): Z_PARAM_STR coerces null to '' — no TypeError (#18617).
var_export(json_decode(null));
echo "\n";
