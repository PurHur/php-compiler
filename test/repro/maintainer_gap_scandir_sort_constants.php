<?php
declare(strict_types=1);

$missing = [];
foreach (['SCANDIR_SORT_ASCENDING', 'SCANDIR_SORT_DESCENDING', 'SCANDIR_SORT_NONE'] as $name) {
    if (!\defined($name)) {
        $missing[] = $name;
    }
}
if ([] !== $missing) {
    fwrite(STDERR, 'FAIL: missing scandir sort constants: '.implode(', ', $missing)."\n");
    exit(1);
}

$expected = [
    'SCANDIR_SORT_ASCENDING' => 0,
    'SCANDIR_SORT_DESCENDING' => 1,
    'SCANDIR_SORT_NONE' => 2,
];
foreach ($expected as $name => $value) {
    $actual = \constant($name);
    if ($actual !== $value) {
        fwrite(STDERR, "FAIL: $name = $actual, expected $value\n");
        exit(1);
    }
}

echo "OK\n";
