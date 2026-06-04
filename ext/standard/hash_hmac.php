<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** hash_hmac() — sha256, sha1, md5 (VM + JIT/AOT via __compiler_hash_hmac). */
final class hash_hmac extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \LogicException('hash_hmac() requires three or four arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $algo = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'hash_hmac', 0, 'algo');
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'hash_hmac', 1, 'data');
        $key = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'hash_hmac', 2, 'key');
        $raw = false;
        if (4 === $argc) {
            $rawArg = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $rawArg->type) {
                throw new \LogicException('hash_hmac() raw_output must be boolean in this compiler build');
            }
            $raw = $rawArg->toBool();
        }
        $result = VmHash::hashHmac($algo, $data, $key, $raw);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 3 || \count($args) > 4) {
            throw new \LogicException('hash_hmac() requires three or four arguments in this compiler build');
        }
        $raw = $context->getTypeFromString('int1')->constInt(0, false);
        if (isset($args[3])) { $raw = JitBoolArg::lower($context, $args[3], 'hash_hmac() raw_output'); }
        return JitHash::hashHmac(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'hash_hmac', 0, 'algo'),
            JitStringBuiltinArg::lower($context, $args[1], 'hash_hmac', 1, 'data'),
            JitStringBuiltinArg::lower($context, $args[2], 'hash_hmac', 2, 'key'),
            $raw
        );
    }
}
