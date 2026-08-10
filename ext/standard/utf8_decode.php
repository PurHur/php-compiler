<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** utf8_decode() — UTF-8 to ISO-8859-1 (php-src ext/standard/basic_functions.c). */
final class utf8_decode extends Internal
{
    public function __construct()
    {
        parent::__construct('utf8_decode');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('utf8_decode() requires exactly one argument in this compiler build');
        }
        Utf8EndecDeprecation::emitVm($frame, 'utf8_decode');
        // Z_PARAM_STR $string — caller strict_types → TypeError on null; else soft-null (#29889, re-#18591).
        $data = VmString::trimFamilyStringArgForFrame($frame, 0, 'utf8_decode', 0, 'string');
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(VmString::utf8_decode($data))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('utf8_decode() requires exactly one argument in this compiler build');
        }
        // Null → TypeError under strict without helper IR after abort (peer quotemeta #21180 / #29889).
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            if ($context->callerStrictTypes) {
                JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'utf8_decode', 0, 'string');

                return $context->getTypeFromString('__string__*')->constNull();
            }

            return JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'utf8_decode', 0, 'string');
        }
        $data = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'utf8_decode', 0, 'string')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'utf8_decode', 0, 'string');

        return JitUtf8Latin1::decode($context, $data);
    }
}
