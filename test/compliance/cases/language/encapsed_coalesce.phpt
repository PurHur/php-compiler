--TEST--
Language: encapsed string ${... ?? ...} null coalesce (PHP 8.3+, #14024)
--FILE--
<?php

declare(strict_types=1);

$s = ['k' => 'val'];
echo "{$s['k'] ?? 'fallback'}";
echo "\n";
echo "{$s['missing'] ?? 'fallback'}";
echo "\n";
$a = ['b' => 1];
echo "{$a['b'] ?? 0}";
echo "\n";
echo "{$a['missing'] ?? 'nil'}";
echo "\n";
echo "prefix{$a['missing'] ?? 'nil'}suffix";
echo "\n";
--EXPECT--
val
fallback
1
nil
prefixnilsuffix
