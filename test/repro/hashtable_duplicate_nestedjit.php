<?php
declare(strict_types=1);
// Trigger NestedJIT of HashTableJitHelper via (array) cast / HashTableDuplicateRuntime
$a = [1, 2, 'k' => 'v'];
$b = (array) $a;
var_export($b);
echo "\n";
