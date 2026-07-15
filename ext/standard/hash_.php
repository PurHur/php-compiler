<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
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
        $algo = VmString::stringBuiltinArgForFrame($frame, 0, 'hash', 0, 'algo');
        $data = self::vmDataArg($frame);
        $raw = false;
        if (isset($frame->calledArgs[2])) {
            $raw = VmMath::parseBoolBuiltinArg($frame->calledArgs[2], 'hash', 3, 'binary');
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
            $raw = JitBoolArg::lower($context, $args[2], 'hash(): Argument #3 ($binary)');
        }

        return JitHash::hash(
            $context,
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'hash', 0, 'algo'),
            self::jitDataArg($context, $args[1]),
            $raw
        );
    }

    /** Z_PARAM_STR $data — null TypeError on 8.4 forward profile (#19275, ext/hash/hash.c). */
    private static function vmDataArg(Frame $frame): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            InternalStrictArg::requireString($frame, 1, 'hash', 'data');

            return $frame->calledArgs[1]->resolveIndirect()->toString();
        }

        return VmString::coerceZparamStrBuiltinArg(
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

        return JitStringBuiltinArg::lowerZparamStr(
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
