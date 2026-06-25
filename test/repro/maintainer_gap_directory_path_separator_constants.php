<?php

declare(strict_types=1);

if (!defined('DIRECTORY_SEPARATOR') || !defined('PATH_SEPARATOR')) {
    echo "separators_fail:undefined\n";
    exit(1);
}
if ('/' !== DIRECTORY_SEPARATOR || ':' !== PATH_SEPARATOR) {
    echo 'separators_fail:values:', DIRECTORY_SEPARATOR, ':', PATH_SEPARATOR, "\n";
    exit(1);
}
$parts = explode(PATH_SEPARATOR, get_include_path());
if (!is_array($parts) || [] === $parts) {
    echo "separators_fail:include_path\n";
    exit(1);
}
echo "separators_ok\n";
