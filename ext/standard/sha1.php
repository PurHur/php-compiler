<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/** sha1() — hex digest via native __compiler_hash (issue #2160; #21181 null DEP+coerce on 8.4). */
final class sha1 extends Internal
{
    public function __construct()
    {
        parent::__construct('sha1');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/basic_functions.stub.php — ArgumentCountError (#28313).
        $this->requireArgCountRange($frame, 'sha1', 1, 2);
        $argc = \count($frame->calledArgs);
        if (null === $frame->returnVar) {
            return;
        }
        $data = self::vmStringArg($frame);
        $raw = false;
        if (2 === $argc) {
            // Z_PARAM_BOOL: caller strict_types → TypeError on null; else soft-null DEP+coerce (#31358).
            $raw = VmMath::parseBoolBuiltinArgForFrame($frame, 1, 'sha1', 2, 'binary');
        }
        $result = VmHash::hash('sha1', $data, $raw);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT try/catch) — peer basename #28286 / #28313.
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            $slot = JitValueBox::alloc($context);
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                $argc < 1
                    ? \sprintf('sha1() expects at least 1 argument, %d given', $argc)
                    : \sprintf('sha1() expects at most 2 arguments, %d given', $argc)
            );

            return $slot;
        }
        $raw = $context->getTypeFromString('int1')->constInt(0, false);
        if (isset($args[1])) {
            // Compile-time null under strict: catchable TypeError then stop IR (#31358 / peer #31288).
            if ($context->callerStrictTypes && (
                JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)
            )) {
                JitNativeString::ensureInsertBlock($context);
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    'sha1(): Argument #2 ($binary) must be of type bool, null given'
                );
                JitNativeString::ensureInsertBlock($context);
                $slot = JitValueBox::alloc($context);

                return JitValueBox::pointer($context, $slot);
            }
            // Z_PARAM_BOOL: strict TypeError on null; else null→false + E_DEPRECATED (#31358).
            $raw = JitBoolArg::lowerCoerceZParamBool($context, $args[1], 'sha1', 'binary', 2);
        }

        return JitSha1::digest(
            $context,
            self::jitStringArg($context, $args[0]),
            $raw
        );
    }

    /**
     * Z_PARAM_STR $string — non-strict null is E_DEPRECATED + '' on 8.4 (php-src sha1.c / #21181).
     * strict_types still TypeErrors via {@see InternalStrictArg}.
     */
    private static function vmStringArg(Frame $frame): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, 0, 'sha1', 'string')->toString();
        }

        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[0],
            'sha1',
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
                'sha1',
                0,
                'string'
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'sha1',
            0,
            'string'
        );
    }
}
