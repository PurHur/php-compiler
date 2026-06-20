<?php

declare(strict_types=1);

/**
 * Issue #10211 — settype($obj, 'string') must invoke __toString (ext/standard/type.c).
 */

class WithToString
{
    public function __toString(): string
    {
        return 'ok';
    }
}

class WithoutToString
{
}

$x = new WithToString();
settype($x, 'string');
echo 'with: ', $x, "\n";

$y = new WithoutToString();
try {
    settype($y, 'string');
    echo 'without: ', $y, "\n";
} catch (Error $e) {
    echo 'without: ', $e->getMessage(), "\n";
}
