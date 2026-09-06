<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler\Concern;

/**
 * Compile-time constant folding hub (#36230 step 1 / #36387).
 *
 * Property/param default + ternary/expr/magic/array-dim folding lives in
 * {@see CompileTimeParamDefaultAndExprFold}. Cast/unary/binary/coalesce/operand
 * folding lives in {@see CompileTimeUnaryBinaryAndCastFold}. Global const /
 * define / enum-case prescan lives in {@see CompileTimeGlobalConstAndDefineFold};
 * class-const fetch fold in {@see CompileTimeClassConstFetchFold}.
 * Call sites and visibility stay identical so LintCompiler overrides are unaffected.
 */
trait CompileTimeFold
{
}
