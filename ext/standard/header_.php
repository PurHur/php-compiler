<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\HttpResponseCode;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\Web\ResponseContext;
use PHPLLVM\Value;

/**
 * header() for HTTP response headers (AOT/JIT emit via printf; VM delegates to PHP).
 */
final class header_ extends Internal
{
    public function __construct()
    {
        parent::__construct('header');
    }

    public function execute(Frame $frame): void
    {
        $argc = count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('header() requires one to three arguments');
        }
        $line = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'header', 0, 'header');
        $replace = true;
        $responseCode = 0;
        if ($argc >= 2) {
            $replace = VmMath::parseBoolBuiltinArg($frame->calledArgs[1], 'header', 2, 'replace');
        }
        if (3 === $argc) {
            $responseCode = VmMath::parseIntBuiltinArg($frame->calledArgs[2], 'header', 3, 'response_code');
        }
        ResponseContext::assertSafeHeaderLine($line);
        if (0 !== $responseCode) {
            \header($line, $replace, $responseCode);
            ResponseContext::setStatus($responseCode);
        } else {
            \header($line, $replace);
            if (0 === strncasecmp($line, 'Location:', 9) && ResponseContext::isHttpResponseCodeUnset()) {
                ResponseContext::setStatus(302);
            }
        }
        ResponseContext::addHeader($line, $replace);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('header() requires one to three arguments');
        }
        $literal = JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $literal) {
            ResponseContext::assertSafeHeaderLine($literal);
        }
        $line = JitStringBuiltinArg::lower($context, $args[0], 'header', 0, 'header');
        if (3 === $argc) {
            HttpResponseCode::emitStandaloneStatusLine(
                $context,
                JitLongArg::lower($context, $args[2], 'header(): Argument #3 ($response_code)')
            );
        }
        $i32 = $context->getTypeFromString('int32');
        $replaceI32 = $i32->constInt(1, false);
        if ($argc >= 2) {
            $replaceI32 = $context->builder->zExt(
                JitBoolArg::lower($context, $args[1], 'header(): Argument #2 ($replace)'),
                $i32
            );
        }
        JitPendingHeaders::add($context, $line, $replaceI32);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
