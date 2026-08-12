<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * Locale::acceptFromHttp() — php-src locale_methods.c (#20036, AOT #28656).
 *
 * Z_PARAM_STR $header — null TypeError under caller strict_types (#29914, locale.stub.php).
 */
final class LocaleAcceptFromHttp extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('acceptFromHttp');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'Locale::acceptFromHttp() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR — strict null/non-string TypeError (#29914); soft-coerce otherwise.
        $header = VmString::zparamStrBuiltinArgForFrame(
            $frame,
            0,
            'Locale::acceptFromHttp',
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
                'Locale::acceptFromHttp() expects exactly 1 argument, %d given',
                \count($args)
            ));
        }

        return JitLocaleParser::acceptFromHttp($context, $args[0], 'Locale::acceptFromHttp');
    }
}
