--TEST--
strict_types=1 recognized when declare shares <?php line (issue #4411)
--FILE--
<?php declare(strict_types=1);

function f(int $x): int {
    return $x;
}

try {
    var_dump(f("1"));
} catch (TypeError $e) {
    echo "TypeError\n";
}
--EXPECT--
TypeError

