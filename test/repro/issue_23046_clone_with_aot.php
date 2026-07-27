<?php

/**
 * Issue #23046 — clone $obj with { prop: val } must AOT under PHP_COMPILER_PROFILE=8.5.
 *
 * Regression of #19130: runQueue leftover className made the desugared IIFE look like an
 * instance method (thisParamOffset=1) while ARG_RECV expected a free-function param →
 * LogicException: Missing required argument 0.
 *
 * Uses an explicit ctor body (same observable as promoted params). Promoted-ctor AOT
 * property reads are a separate gap (values appear as (v<<8)|TYPE_INTEGER).
 *
 * Run:
 *   PHP_COMPILER_PROFILE=8.5 php bin/vm.php test/repro/issue_23046_clone_with_aot.php
 *   PHP_COMPILER_PROFILE=8.5 php bin/jit.php test/repro/issue_23046_clone_with_aot.php
 *   PHP_COMPILER_PROFILE=8.5 php bin/compile.php test/repro/issue_23046_clone_with_aot.php /tmp/issue_23046.bin \
 *     && /tmp/issue_23046.bin
 */

declare(strict_types=1);

class C {
    public int $x;
    public int $y;

    public function __construct(int $x, int $y = 0)
    {
        $this->x = $x;
        $this->y = $y;
    }
}

$a = new C(1, 2);
$b = clone $a with { x: 9 };
echo $b->x, ',', $b->y, "\n";
