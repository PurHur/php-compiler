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
        if ($argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'hash() expects at most 3 arguments, %d given',
                $argc
            ));
        }
        $algo = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'hash', 0, 'algo');
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'hash', 1, 'data');
        $raw = false;
        if (3 === $argc) {
            $raw = VmMath::parseBoolBuiltinArg($frame->calledArgs[2], 'hash', 3, 'binary');
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
        if ($argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'hash() expects at most 3 arguments, %d given',
                $argc
            ));
        }
        $raw = $context->getTypeFromString('int1')->constInt(0, false);
        if (isset($args[2])) {
            $raw = JitBoolArg::lower($context, $args[2], 'hash(): Argument #3 ($binary)');
        }
        return JitHash::hash(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'hash', 0, 'algo'),
            JitStringBuiltinArg::lower($context, $args[1], 'hash', 1, 'data'),
            $raw
        );
    }
}
