<?php
declare(strict_types=1);

if (!function_exists('json_validate')) {
    echo "missing\n";
    exit(1);
}

echo 'registered', "\n";
echo json_validate('{"a":1}') ? 'valid' : 'invalid', "\n";
echo json_validate('{') ? 'valid' : 'invalid', "\n";
