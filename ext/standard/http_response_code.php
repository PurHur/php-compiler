<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitHttpResponseCodeArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * http_response_code() — get/set HTTP status for the current response (VM ResponseContext; JIT global + Status: line, issues #252, #280, #6591, #7322).
 */
final class http_response_code extends Internal
{
    public function __construct()
    {
        parent::__construct('http_response_code');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext;
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('http_response_code() accepts at most one argument');
        }
        if (null === $frame->returnVar) {
            if (0 === $argc) {
                return;
            }
            $arg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                $code = VmHttpResponse::resolveCodeArg($frame->calledArgs[0], 'http_response_code');
                if (0 !== $code) {
                    VmHttpResponse::writeHttpResponseCode($code);
                }
            }

            return;
        }
        if (0 === $argc) {
            VmHttpResponse::assignReadResult(
                $frame->returnVar,
                VmHttpResponse::readHttpResponseCode($ctx),
                $ctx
            );

            return;
        }
        $arg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_NULL === $arg->type) {
            VmHttpResponse::assignReadResult(
                $frame->returnVar,
                VmHttpResponse::readHttpResponseCode($ctx),
                $ctx
            );

            return;
        }
        $code = VmHttpResponse::resolveCodeArg($frame->calledArgs[0], 'http_response_code');
        // php-src head.c: response_code 0 is falsy — getter only, no status change (#9306).
        if (0 === $code) {
            VmHttpResponse::assignReadResult(
                $frame->returnVar,
                VmHttpResponse::readHttpResponseCode($ctx),
                $ctx
            );

            return;
        }
        VmHttpResponse::assignWriteResult(
            $frame->returnVar,
            VmHttpResponse::writeHttpResponseCode($code),
            $ctx
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 === \count($args)) {
            JitHttpResponseCodeArg::lower($context, $args[0], 'http_response_code() code');
        }

        return \call_user_func_array([JitHttpResponseCode::class, 'invoke'], array_merge([$context], $args));
    }
}
