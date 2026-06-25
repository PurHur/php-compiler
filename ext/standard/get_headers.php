<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * get_headers() — HTTP HEAD via VmHttpFetchPure / VmStreamSocketNative, no host get_headers() (#3309, #8939).
 *
 * php-src: ext/standard/head.c — PHP_FUNCTION(get_headers)
 */
final class get_headers extends Internal
{
    public function __construct()
    {
        parent::__construct('get_headers');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'get_headers() expects at least 1 argument, '.\max(0, $argc - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $url = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'get_headers', 0, 'url');
        $associative = false;
        if ($argc >= 2) {
            $associative = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[1],
                'get_headers',
                2,
                'associative'
            );
        }

        if (!VmHttpLastResponseHeaders::isHttpUrl($url)) {
            $frame->returnVar->bool(false);

            return;
        }

        $headers = VmHttpFetchNative::fetchHeaders($url);
        if (false === $headers) {
            $frame->returnVar->bool(false);

            return;
        }

        $formatted = VmHttpHeaders::format($headers, $associative);
        $frame->returnVar->array(VmHttpHeaders::toHashTable($formatted, $associative));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('get_headers() is not supported in JIT/AOT in this compiler build');
    }
}
