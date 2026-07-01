<?php

declare(strict_types=1);

$a = [1, 2];
$errors = [];

try {
    array_walk($a, null);
    echo "array_walk uncaught\n";
    exit(1);
} catch (TypeError $e) {
    $errors[] = $e->getMessage();
}

try {
    array_walk_recursive($a, null);
    echo "array_walk_recursive uncaught\n";
    exit(1);
} catch (TypeError $e) {
    $errors[] = $e->getMessage();
}

foreach ($errors as $line) {
    echo $line, "\n";
}

$b = [1, 2];
array_walk($b, static function (mixed &$v): void {
    $v *= 2;
});
echo implode(',', $b), "\n";
