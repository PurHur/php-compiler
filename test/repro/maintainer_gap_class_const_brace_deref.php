<?php

declare(strict_types=1);

/**
 * Issue #16597 — class constant brace dereference must parse-fail on 8.2 reference profile.
 *
 * Zend 8.2: parse error (exit 255). VM on reference profile must match after #16597.
 */
class C
{
    public const X = 42;
}

echo C::{'X'};
