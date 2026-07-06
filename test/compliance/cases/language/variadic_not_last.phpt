--TEST--
Language: valid trailing variadic signatures unchanged (#16721)
--FILE--
<?php
function ok(int $a, mixed ...$rest): void {
    echo count($rest), "\n";
}

ok(1, 2, 3);
--EXPECT--
2
