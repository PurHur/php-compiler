<?php
declare(strict_types=1);

// Issue #13945 — statement-context spaceship/relational compare must materialize int/bool.
var_dump(null <=> 1);
var_dump(null < 1);

$ship = null <=> 1;
if (-1 !== $ship) {
    fwrite(STDERR, "assign spaceship: expected -1, got {$ship}\n");
    exit(1);
}
$lt = null < 1;
if (true !== $lt) {
    fwrite(STDERR, 'assign relational: expected true' . "\n");
    exit(1);
}

echo (string) (null <=> 1);
