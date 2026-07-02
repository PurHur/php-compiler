<?php

declare(strict_types=1);

$dir = rtrim(sys_get_temp_dir(), '/').'/';
$matches = glob($dir);
if (!is_array($matches) || 1 !== count($matches)) {
    echo 'fail: glob(trailing slash) expected single match, got '.var_export($matches, true)."\n";
    exit(1);
}
if ($matches[0] !== $dir) {
    echo 'fail: glob(trailing slash) path '.var_export($matches[0], true).' expected '.var_export($dir, true)."\n";
    exit(1);
}

echo "ok\n";
