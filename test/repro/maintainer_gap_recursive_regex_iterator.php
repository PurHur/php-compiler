<?php

declare(strict_types=1);

$it = new RecursiveArrayIterator(['a1', 'b2', 'a3']);
$rx = new RecursiveRegexIterator($it, '/^a/', RecursiveRegexIterator::MATCH);
$seen = [];
foreach ($rx as $value) {
    $seen[] = $value;
}
echo implode(',', $seen), "\n";
