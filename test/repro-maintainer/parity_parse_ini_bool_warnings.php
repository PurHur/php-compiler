<?php

declare(strict_types=1);

error_reporting(E_ALL);
foreach (['on=on', 'on=1', 'on=foo', 'null=foo', 'false=false'] as $ini) {
    echo "=== $ini ===\n";
    parse_ini_string($ini);
}
