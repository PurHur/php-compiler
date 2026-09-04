<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * bcmath extension surfaces needed by lib/JIT Call (#36204).
 *
 * Implemented in {@code ext/bcmath/JitBcMathExtensionHooksFacade.php}; Call
 * BcMathNumber* files must not import {@code ext\bcmath}.
 */
interface BcMathExtensionHooks
{
    /** BcMath\Number::__construct() user-script AOT. */
    public function numberConstruct(Context $context, Variable ...$args): Value;

    /**
     * BcMath\Number::{add,mul,compare} user-script AOT.
     *
     * @return array{0: Value, 1: array{value: string, scale: int}|null}
     */
    public function numberMethod(Context $context, string $method, Variable ...$args): array;

    /** BcMath\Number::__toString() user-script AOT. */
    public function numberToString(Context $context, Variable ...$args): Value;
}
