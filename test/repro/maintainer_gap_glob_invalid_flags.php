<?php

error_reporting(E_ALL);
$count = 0;
set_error_handler(static function () use (&$count): bool {
    ++$count;

    return true;
});

$result = @glob('*', 99999);

echo 'warnings: ', $count, "\n";
echo 'is_false: ', var_export(false === $result, true), "\n";
