<?php

declare(strict_types=1);

/**
 * Issue #16597 — class constant brace dereference must parse-fail on 8.2 reference profile.
 */
class C
{
    public const X = 42;
}

echo C::{'X'}, "\n";
