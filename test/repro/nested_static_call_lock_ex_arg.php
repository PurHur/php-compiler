<?php
// Issue #23848 — nested StaticCall as arg with trailing ConstFetch must EXEC_RETURN (#17697).
class A
{
    public static function path(string $x): string
    {
        return $x;
    }
}

function put(string $p, string $d, int $f): void
{
}

function test(string $id, string $payload): void
{
    put(A::path($id), $payload, \LOCK_EX);
}
