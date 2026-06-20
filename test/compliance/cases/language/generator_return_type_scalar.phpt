--TEST--
Generator return type without yield rejects scalar return (issue #10333)
--FILE--
<?php
declare(strict_types=1);

function g(): Generator {
    return 1;
}

try {
    g();
    echo "no error\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

function ok(): Generator {
    yield 1;
}

$gen = ok();
echo $gen->current(), "\n";
--EXPECT--
TypeError: g(): Return value must be of type Generator, int returned
1
