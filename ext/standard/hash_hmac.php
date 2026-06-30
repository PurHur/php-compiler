<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** hash_hmac() — sha256, sha1, md5 (VM + JIT/AOT via __compiler_hash_hmac). */
final class hash_hmac extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(\sprintf(
                'hash_hmac() expects at least 3 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'hash_hmac() expects at most 4 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $algo = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'hash_hmac', 0, 'algo');
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'hash_hmac', 1, 'data');
        $key = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'hash_hmac', 2, 'key');
        $raw = false;
        if (4 === $argc) {
            $raw = VmMath::parseBoolBuiltinArg($frame->calledArgs[3], 'hash_hmac', 4, 'binary');
        }
        $frame->returnVar->string(VmHash::hashHmac($algo, $data, $key, $raw));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 3) {
            throw new \ArgumentCountError(\sprintf(
                'hash_hmac() expects at least 3 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'hash_hmac() expects at most 4 arguments, %d given',
                $argc
            ));
        }
        $raw = $context->getTypeFromString('int1')->constInt(0, false);
        if (isset($args[3])) {
            $raw = JitBoolArg::lower($context, $args[3], 'hash_hmac(): Argument #4 ($binary)');
        }
        return JitHash::hashHmac(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'hash_hmac', 0, 'algo'),
            JitStringBuiltinArg::lower($context, $args[1], 'hash_hmac', 1, 'data'),
            JitStringBuiltinArg::lower($context, $args[2], 'hash_hmac', 2, 'key'),
            $raw
        );
    }
}
