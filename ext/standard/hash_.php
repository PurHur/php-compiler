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

/** hash() — sha256, sha1, md5 (VM + JIT/AOT via __compiler_hash). */
final class hash_ extends Internal
{
    public function __construct()
    {
        parent::__construct('hash');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('hash() requires two or three arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $algo = $frame->calledArgs[0]->resolveIndirect();
        $data = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $algo->type || Variable::TYPE_STRING !== $data->type) {
            throw new \LogicException('hash() requires string algorithm and data in this compiler build');
        }
        $raw = false;
        if (3 === $argc) {
            $rawArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_BOOL !== $rawArg->type) {
                throw new \LogicException('hash() raw_output must be boolean in this compiler build');
            }
            $raw = $rawArg->toBool();
        }
        $result = VmHash::hash($algo->toString(), $data->toString(), $raw);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2 || \count($args) > 3) {
            throw new \LogicException('hash() requires two or three arguments in this compiler build');
        }
        $raw = $context->getTypeFromString('int1')->constInt(0, false);
        if (isset($args[2])) { $raw = JitBoolArg::lower($context, $args[2], 'hash() raw_output'); }
        return JitHash::hash($context, JitStringArg::lower($context, $args[0], 'hash() algorithm'), JitStringArg::lower($context, $args[1], 'hash() data'), $raw);
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

        throw new \LogicException('hash() only supports strings in this compiler build');
    }
}
