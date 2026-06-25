<?php
declare(strict_types=1);
$classes = get_declared_classes();
$n = 0;
foreach ($classes as $c) {
    if (str_starts_with($c, 'PHPCompiler\\')) {
        echo $c, "\n";
        if (++$n >= 5) {
            break;
        }
    }
}
echo 'phpc_count=', count(array_filter($classes, static fn (string $c): bool => str_starts_with($c, 'PHPCompiler\\'))), "\n";
