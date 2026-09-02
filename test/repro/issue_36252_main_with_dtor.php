<?php

declare(strict_types=1);

// Trigger hasUserDestructors() so {main} sets allowDelref=false (#4013).
class D { public function __destruct() {} }

$n = (int) ($argv[1] ?? 16000);
$a = [];
for ($i = 0; $i < $n; $i++) {
    $a[] = $i;
}
echo count($a), "\n";
