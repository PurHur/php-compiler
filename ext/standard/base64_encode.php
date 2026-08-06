<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringBase64Encode;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/** base64_encode() — RFC 4648 standard alphabet (subset of PHP). */
final class base64_encode extends Internal
{
    public function __construct()
    {
        parent::__construct('base64_encode');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/base64.stub.php — ArgumentCountError (#28316).
        $this->requireExactArgCount($frame, 'base64_encode', 1);
        $data = self::vmStringArg($frame);
        BuiltinExecute::writeReturn($frame, static function ($ret) use ($data): void {
            $ret->string(VmString::base64_encode($data));
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT try/catch) — peer #28317 / #28316.
        if (1 !== \count($args)) {
            $unreachable = $context->getTypeFromString('__string__*')->constNull();
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('base64_encode() expects exactly 1 argument, %d given', \count($args))
            );

            return $unreachable;
        }
        // Null → soft-coerce to "" without helper IR (base64_encode("") === ""; #21188).
        if (JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false)) {
            if ($context->callerStrictTypes) {
                JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'base64_encode', 0, 'string');

                return $context->getTypeFromString('__string__*')->constNull();
            }

            return JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'base64_encode', 0, 'string');
        }
        $literal = $args[0]->compileTimeString ?? JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $literal) {
            return $context->builder->load(
                $context->constantStringFromString(VmString::base64_encode($literal))
            );
        }

        StringBase64Encode::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_base64_encode'),
            self::jitStringArg($context, $args[0])
        );
    }

    /** Soft-null — coerce+deprecate on forward profile (#21188, ext/standard/base64.c). */
    private static function vmStringArg(Frame $frame): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, 0, 'base64_encode', 'string')->toString();
        }

        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[0],
            'base64_encode',
            0,
            'string'
        );
    }

    private static function jitStringArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'base64_encode',
                0,
                'string'
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'base64_encode',
            0,
            'string'
        );
    }
}
