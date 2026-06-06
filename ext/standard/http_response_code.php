<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\ResponseContext;
use PHPLLVM\Value;

/**
 * http_response_code() — get/set HTTP status for the current response (VM ResponseContext; JIT global + Status: line, issues #252, #280, #6591).
 */
final class http_response_code extends Internal
{
    public function __construct()
    {
        parent::__construct('http_response_code');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('http_response_code() accepts at most one argument');
        }
        if (null === $frame->returnVar) {
            if (0 === $argc) {
                return;
            }
            $arg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type && Variable::TYPE_INTEGER === $arg->type) {
                ResponseContext::writeHttpResponseCode($arg->toInt());
            }

            return;
        }
        if (0 === $argc) {
            self::assignReadResult($frame->returnVar, ResponseContext::readHttpResponseCode());

            return;
        }
        $arg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_NULL === $arg->type) {
            self::assignReadResult($frame->returnVar, ResponseContext::readHttpResponseCode());

            return;
        }
        if (Variable::TYPE_INTEGER !== $arg->type) {
            throw new \LogicException('http_response_code() response_code must be an integer in this compiler build');
        }
        self::assignWriteResult($frame->returnVar, ResponseContext::writeHttpResponseCode($arg->toInt()));
    }

    /** @param int|false $value */
    private static function assignReadResult(Variable $dest, $value): void
    {
        if (false === $value) {
            $dest->bool(false);

            return;
        }
        $dest->int($value);
    }

    /** @param true|int|false $value */
    private static function assignWriteResult(Variable $dest, $value): void
    {
        if (false === $value) {
            $dest->bool(false);

            return;
        }
        if (true === $value) {
            $dest->bool(true);

            return;
        }
        $dest->int($value);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 === \count($args)) {
            JitLongArg::lower($context, $args[0], 'http_response_code() code');
        }

        return \call_user_func_array([JitHttpResponseCode::class, 'invoke'], array_merge([$context], $args));
    }
}
