<?php

declare(strict_types=1);

/**
 * #29291 AOT compile-only — empty $break ValueError path must lower without LogicException.
 * Native execute of wordwrap empty-break remains a pre-existing AOT SEGV (peer WordwrapCutNull /
 * WordwrapBuiltinTest); message correctness is covered by JIT via WordwrapJitHelper.
 */
$empty = substr('x', 1);
try {
    wordwrap('abcd', 2, $empty, true);
    echo "miss\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
