<?php

declare(strict_types=1);

// AOT: json_encode after loop `$rows[]=` must not fold INIT `[]` (#36385 / peer #33709).
$rows = [];
for ($i = 0; $i < 5; ++$i) {
    $rows[] = $i;
}
echo json_encode($rows), PHP_EOL;
