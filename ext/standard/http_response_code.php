<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitHttpResponseCodeArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
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
            if (Variable::TYPE_NULL === $frame->calledArgs[0]->resolveIndirect()->type) {
                // Z_PARAM_LONG: declare(strict_types=1) → TypeError; else soft-null DEP+coerce (#30019).
                if (InternalStrictArg::isCallerStrict($frame)) {
                    throw new \TypeError(
                        'http_response_code(): Argument #1 ($response_code) must be of type int, null given'
                    );
                }
                VmNullNumberParamDeprecation::emit($frame, 'http_response_code', 1, 'response_code', 'int');

                return;
            }
            $code = VmHttpResponse::resolveCodeArg($frame->calledArgs[0], 'http_response_code');
            if (0 !== $code) {
                if (VmSapiHeaderGuard::headersAlreadySent($frame)) {
                    VmSapiHeaderGuard::warnCannotSetResponseCode($frame);

                    return;
                }
                VmHttpResponse::writeHttpResponseCode($code);
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
        if (Variable::TYPE_NULL === $frame->calledArgs[0]->resolveIndirect()->type) {
            // Z_PARAM_LONG: declare(strict_types=1) → TypeError; else soft-null DEP+coerce (#30019).
            if (InternalStrictArg::isCallerStrict($frame)) {
                throw new \TypeError(
                    'http_response_code(): Argument #1 ($response_code) must be of type int, null given'
                );
            }
            VmNullNumberParamDeprecation::emit($frame, 'http_response_code', 1, 'response_code', 'int');
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
        // php-src head.c: SG(headers_sent) → Warning + false, status unchanged (#28929).
        if (VmSapiHeaderGuard::headersAlreadySent($frame)) {
            VmSapiHeaderGuard::warnCannotSetResponseCode($frame);
            VmHttpResponse::assignWriteResult($frame->returnVar, false, $ctx);

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
            $arg = $args[0];
            // Literal null is handled once in JitHttpResponseCode::invoke (#30019).
            if (JITVariable::TYPE_NULL !== $arg->type && !$arg->isNullConstant) {
                JitHttpResponseCodeArg::lower($context, $arg, 'http_response_code() code');
            }
        }

        return \call_user_func_array([JitHttpResponseCode::class, 'invoke'], array_merge([$context], $args));
    }
}
