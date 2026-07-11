<?php

declare(strict_types=1);

$assigned = class_alias('NoSuchClass', 'AliasMissing17756');
echo 'assign: ', var_export($assigned, true), "\n";
echo 'inline: ', var_export(false !== class_alias('NoSuchClass2', 'AliasMissing17756b'), true), "\n";

if (false !== class_alias('NoSuchClass3', 'AliasMissing17756c')) {
    echo "fail: missing class_alias source must compare as false\n";
    exit(1);
}

echo "ok\n";
