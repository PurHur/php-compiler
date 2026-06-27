<?php
declare(strict_types=1);

$decoded = json_decode('9999999999999999999', false, 512, JSON_BIGINT_AS_STRING);
var_export($decoded);
echo "\n";
