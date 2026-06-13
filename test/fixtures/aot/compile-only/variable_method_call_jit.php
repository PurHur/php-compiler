<?php

declare(strict_types=1);

/**
 * Issue #8407: JIT compile must not segfault on $obj->method() when $obj is a script global.
 */
class MiniC {
    public function foo(mixed $x): int {
        return 1;
    }
}

$c = new MiniC();
$key = new stdClass();
echo $c->foo($key);
