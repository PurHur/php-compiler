<?php

declare(strict_types=1);

$dir = __DIR__ . '/glob_flags_named_fixture';
if (!is_dir($dir)) {
    mkdir($dir);
}
file_put_contents($dir . '/z.php', '<?php');
file_put_contents($dir . '/a.php', '<?php');

$matches = glob($dir . '/*.php', flags: GLOB_NOSORT);
if (!is_array($matches) || 2 !== count($matches)) {
    echo 'fail: expected two matches, got ', var_export($matches, true), "\n";
    exit(1);
}

$positional = glob($dir . '/*.php', GLOB_NOSORT);
if ($matches !== $positional) {
    echo "fail: named flags must match positional GLOB_NOSORT\n";
    exit(1);
}

array_map('unlink', glob($dir . '/*'));
rmdir($dir);

echo "ok\n";
