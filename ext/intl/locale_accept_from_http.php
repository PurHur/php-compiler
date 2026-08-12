<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * locale_accept_from_http() — HTTP Accept-Language negotiate (#20036, AOT #28656).
 *
 * Z_PARAM_STR $header — null TypeError under caller strict_types (#29914, locale.stub.php).
 */
final class locale_accept_from_http extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(\sprintf(
                'locale_accept_from_http() expects exactly 1 argument, %d given',
                \count($frame->calledArgs)
            ));
        }
        // Z_PARAM_STR — strict null/non-string TypeError (#29914); soft-coerce otherwise.
        $header = VmString::zparamStrBuiltinArgForFrame(
            $frame,
            0,
            'locale_accept_from_http',
            0,
            'header'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmLocale::acceptFromHttp($header);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(\sprintf(
                'locale_accept_from_http() expects exactly 1 argument, %d given',
                \count($args)
            ));
        }

        return JitLocaleParser::acceptFromHttp($context, $args[0], 'locale_accept_from_http');
    }
}
