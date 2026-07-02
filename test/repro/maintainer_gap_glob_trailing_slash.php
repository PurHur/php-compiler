<?php

declare(strict_types=1);

$dir = rtrim(sys_get_temp_dir(), '/').'/';
$matches = glob($dir);
if (!is_array($matches) || 1 !== count($matches)) {
    echo 'fail: glob(temp_dir/) expected single match, got '.var_export($matches, true)."\n";
    exit(1);
}
if ($matches[0] !== $dir) {
    echo 'fail: glob(temp_dir/) path '.var_export($matches[0], true).' expected '.var_export($dir, true)."\n";
    exit(1);
}

$rel = glob('ext/');
if (!is_array($rel) || 1 !== count($rel) || 'ext/' !== $rel[0]) {
    echo 'fail: glob(ext/) got '.var_export($rel, true)."\n";
    exit(1);
}

echo "ok\n";
