<?php
declare(strict_types=1);

var_export(defined('UUID_TYPE_RANDOM'));
echo PHP_EOL;
var_export(function_exists('uuid_create'));
echo PHP_EOL;
var_export(function_exists('uuid_generate'));
echo PHP_EOL;

$id = uuid_create(UUID_TYPE_RANDOM);
var_export(\is_string($id));
echo PHP_EOL;
var_export(\strlen($id));
echo PHP_EOL;
var_export((bool) \preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id));
echo PHP_EOL;

$timeId = uuid_create(UUID_TYPE_TIME);
var_export((bool) \preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-1[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $timeId));
echo PHP_EOL;

$out = '';
uuid_generate($out);
var_export(\is_string($out) && 36 === \strlen($out));
echo PHP_EOL;

try {
    uuid_create(99);
    echo "bad_type\n";
} catch (\ValueError $e) {
    echo "invalid_type\n";
}
