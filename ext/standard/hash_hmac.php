<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitStringArg;
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
        $algo = $frame->calledArgs[0]->resolveIndirect();
        $data = $frame->calledArgs[1]->resolveIndirect();
        $key = $frame->calledArgs[2]->resolveIndirect();
        if (
            Variable::TYPE_STRING !== $algo->type
            || Variable::TYPE_STRING !== $data->type
            || Variable::TYPE_STRING !== $key->type
        ) {
            throw new \LogicException('hash_hmac() requires string algorithm, data, and key in this compiler build');
        }
        $raw = false;
        if (4 === $argc) {
            $rawArg = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_BOOL !== $rawArg->type) {
                throw new \LogicException('hash_hmac() raw_output must be boolean in this compiler build');
            }
            $raw = $rawArg->toBool();
        }
        $result = VmHash::hashHmac($algo->toString(), $data->toString(), $key->toString(), $raw);
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
        return JitHash::hashHmac($context, JitStringArg::lower($context, $args[0], 'hash_hmac() algorithm'), JitStringArg::lower($context, $args[1], 'hash_hmac() data'), JitStringArg::lower($context, $args[2], 'hash_hmac() key'), $raw);
    }

    private static function jitStringArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readString'),
                $arg->value
            );
        }

        throw new \LogicException('hash_hmac() only supports strings in this compiler build');
    }
}
