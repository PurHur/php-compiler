<?php

declare(strict_types=1);

$assigned = ini_get('bogus_xyz');
echo 'assign: ', var_export($assigned, true), "\n";
echo 'inline: ', var_export(false !== ini_get('bogus_xyz'), true), "\n";

if (false !== ini_get('bogus_xyz')) {
    echo "fail: unknown ini_get key must compare as false\n";
    exit(1);
}

echo "ok\n";
