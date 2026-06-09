--TEST--
stdlib fputcsv() — empty escape/enclosure ValueError parity (#4530, ext/standard/file.c)
--FILE--
<?php
$fp = fopen('php://memory', 'r+');
foreach ([
    ['escape' => ''],
    ['enclosure' => ''],
    ['enclosure' => 'ab'],
    ['separator' => ''],
    ['escape' => 'ab'],
] as $case) {
    try {
        fputcsv($fp, ['a'], $case['separator'] ?? ',', $case['enclosure'] ?? '"', $case['escape'] ?? '\\');
        echo "no throw\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
--EXPECT--
no throw
ValueError: fputcsv(): Argument #4 ($enclosure) must be a single character
ValueError: fputcsv(): Argument #4 ($enclosure) must be a single character
ValueError: fputcsv(): Argument #3 ($separator) must be a single character
ValueError: fputcsv(): Argument #5 ($escape) must be empty or a single character
