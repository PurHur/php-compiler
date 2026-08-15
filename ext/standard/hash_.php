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
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** hash() — sha256, sha1, md5, crc32*, adler32, fnv*, xxh3/xxh128 (VM + JIT/AOT via __compiler_hash). */
final class hash_ extends Internal
{
    public function __construct()
    {
        parent::__construct('hash');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'hash() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if (self::maxCalledArgIndex($frame->calledArgs) > 3) {
            throw new \ArgumentCountError(\sprintf(
                'hash() expects at most 4 arguments, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR $algo — non-strict null is E_DEPRECATED + '' then ValueError (#21490, reverts #20304).
        $algo = VmString::trimFamilyStringArgForFrame($frame, 0, 'hash', 0, 'algo');
        $data = self::vmDataArg($frame);
        $raw = false;
        if (isset($frame->calledArgs[2])) {
            // Z_PARAM_BOOL: caller strict_types → TypeError on null; else soft-null DEP+coerce (#31288).
            $raw = VmMath::parseBoolBuiltinArgForFrame($frame, 2, 'hash', 3, 'binary');
        }
        if (isset($frame->calledArgs[3])) {
            VmArray::requireArrayParam($frame->calledArgs[3], 'hash', 4, 'options');
        }
        $result = VmHash::hash($algo, $data, $raw);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->string($result);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'hash() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'hash() expects at most 4 arguments, %d given',
                $argc
            ));
        }
        $raw = $context->getTypeFromString('int1')->constInt(0, false);
        if (isset($args[2])) {
            // Compile-time null under strict: catchable TypeError then stop IR (#31288 / peer #31245).
            if ($context->callerStrictTypes && (
                JITVariable::TYPE_NULL === $args[2]->type || ($args[2]->isNullConstant ?? false)
            )) {
                JitNativeString::ensureInsertBlock($context);
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    'hash(): Argument #3 ($binary) must be of type bool, null given'
                );
                JitNativeString::ensureInsertBlock($context);
                $slot = JitValueBox::alloc($context);

                return JitValueBox::pointer($context, $slot);
            }
            // Z_PARAM_BOOL: strict TypeError on null; else null→false + E_DEPRECATED (#31288).
            $raw = JitBoolArg::lowerCoerceZParamBool($context, $args[2], 'hash', 'binary', 3);
        }

        return JitHash::hash(
            $context,
            self::jitAlgoArg($context, $args[0]),
            self::jitDataArg($context, $args[1]),
            $raw
        );
    }

    /** Z_PARAM_STR $algo — soft-null then ValueError on empty/unknown (#21490, ext/hash/hash.c). */
    private static function jitAlgoArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'hash',
                0,
                'algo'
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'hash',
            0,
            'algo'
        );
    }

    /**
     * Z_PARAM_STR $data — non-strict null is E_DEPRECATED + '' on 8.4 (php-src hash.c / #21181).
     * Reverts mistaken #19275 forward-profile TypeError; strict_types still TypeErrors.
     */
    private static function vmDataArg(Frame $frame): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            InternalStrictArg::requireString($frame, 1, 'hash', 'data');

            return $frame->calledArgs[1]->resolveIndirect()->toString();
        }

        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[1],
            'hash',
            1,
            'data'
        );
    }

    private static function jitDataArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'hash',
                1,
                'data'
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'hash',
            1,
            'data'
        );
    }

    /**
     * @param array<int, Variable> $calledArgs
     */
    private static function maxCalledArgIndex(array $calledArgs): int
    {
        if ([] === $calledArgs) {
            return -1;
        }

        return max(array_keys($calledArgs));
    }
}
