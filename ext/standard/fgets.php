<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * fgets() — VM via VmFs; JIT/AOT via __compiler_fgets (issue #1187).
 *
 * Excess/missing argc → Zend ArgumentCountError (#30721; php-src ext/standard/file.c).
 */
final class fgets extends Internal
{
    private const LENGTH_ERROR = 'fgets(): Argument #2 ($length) must be greater than 0';

    public function __construct()
    {
        parent::__construct('fgets');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: 1..2 (#30721; ext/standard/file.c / file.stub.php).
        $this->requireArgCountRange($frame, 'fgets', 1, 2);
        $argc = \count($frame->calledArgs);
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'fgets');
        if (null === $frame->returnVar) {
            return;
        }
        $length = null;
        if (2 === $argc) {
            // php-src file.stub.php: ?int $length = null — null ≡ omit length (#29506).
            $length = VmMath::parseNullableIntBuiltinArgForFrame($frame, 1, 'fgets', 2, 'length');
            if (null !== $length && $length <= 0) {
                throw new \ValueError(self::LENGTH_ERROR);
            }
        }
        $line = VmFs::fgets($handle, $length);
        if (false === $line) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($line);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30721).
        if (!$this->requireArgCountRangeJit($context, $args, 'fgets', 1, 2)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $argc = \count($args);
        $i64 = $context->getTypeFromString('int64');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'fgets() handle'),
            $i64
        );
        if (2 === $argc) {
            // Null → omit-length sentinel -1 without >0 guard (explicit 0/-1 still ValueError).
            $length = JitFgets::lowerNullableLengthArg($context, $args[1]);
        } else {
            $length = $i64->constInt(-1, true);
        }

        return JitFgets::invoke($context, $handle, $length);
    }
}
