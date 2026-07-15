<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** crc32() — CRC32B (IEEE), signed 32-bit int (VM + JIT/AOT via ext/standard/VmCrc32.php). */
final class crc32 extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('crc32() requires one or two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $subject = self::vmStringArg($frame, 0);
        $seed = 0;
        if (2 === $argc) {
            $seedArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $seedArg->type) {
                throw new \LogicException('crc32() seed must be an integer in this compiler build');
            }
            $seed = $seedArg->toInt();
        }
        $frame->returnVar->int(VmCrc32::compute($subject, $seed));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1 || \count($args) > 2) {
            throw new \LogicException('crc32() requires one or two arguments in this compiler build');
        }
        $subject = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'crc32', 0, 'string')
            : JitStringBuiltinArg::lowerZparamStr($context, $args[0], 'crc32', 0, 'string');
        $seed = $context->getTypeFromString('int64')->constInt(0, false);
        if (isset($args[1])) {
            $seed = JitLongArg::lower($context, $args[1], 'crc32() seed');
        }

        return JitCrc32::compute($context, $subject, $seed);
    }

    private static function vmStringArg(Frame $frame, int $argIndex): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'crc32', 'string')->toString();
        }

        return VmString::coerceZparamStrBuiltinArg(
            $frame->calledArgs[$argIndex],
            'crc32',
            $argIndex,
            'string'
        );
    }
}
