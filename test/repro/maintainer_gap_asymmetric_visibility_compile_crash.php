<?php

declare(strict_types=1);

// Bare/parenthesized asymmetric visibility on PROFILE=8.4 (#18820).
// Property defaults with `new` compile-reject like Zend (#21493, #21869) — see
// test/compliance/cases/language/bare_public_private_set_property_new_reject_84.phpt.

class PrivateSetBare {
    public private(set) string $x = 'hi';
}

class PrivateSetParen {
    public (private(set)) int $n = 1;
}

$bare = new PrivateSetBare();
echo $bare->x, "\n";
try {
    $bare->x = 'no';
    echo "bare write ok\n";
} catch (Error $e) {
    echo "bare write blocked\n";
}

$p = new PrivateSetParen();
echo $p->n, "\n";

echo "asymmetric ok\n";
