<?php

declare(strict_types=1);

/**
 * #29291 AOT probe — non-literal empty break (literal '' may abort at compile-time).
 * Compile must succeed; native execute of wordwrap empty-break currently SEGVs on AOT
 * (pre-existing — peer WordwrapCutNull / WordwrapBuiltinTest). Wording is covered by
 * JIT via WordwrapJitHelper (same helper AOT links).
 */
$empty = substr('x', 1);
wordwrap('abcd', 2, $empty, true);
