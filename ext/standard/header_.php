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
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\Web\ResponseContext;
use PHPLLVM\Value;

/**
 * header() for HTTP response headers (VM ResponseContext + JIT pending queue; issue #5344, #8274).
 *
 * Z_PARAM_STR $header — null TypeError on 8.4 forward profile (#19224, php-src ext/standard/head.c).
 */
final class header_ extends Internal
{
    public function __construct()
    {
        parent::__construct('header');
    }

    public function execute(Frame $frame): void
    {
        foreach (\array_keys($frame->calledArgs) as $idx) {
            if ($idx < 0 || $idx > 2) {
                throw new \LogicException('header() requires one to three arguments');
            }
        }
        if (!isset($frame->calledArgs[0])) {
            throw new \LogicException('header() requires one to three arguments');
        }
        // php-src ZEND_PARSE_PARAMETERS before sapi headers_sent (#19224, ext/standard/head.c).
        $line = self::vmHeaderArg($frame);
        $replace = true;
        $responseCode = 0;
        if (isset($frame->calledArgs[1])) {
            $replace = VmMath::parseBoolBuiltinArg($frame->calledArgs[1], 'header', 2, 'replace');
        }
        if (isset($frame->calledArgs[2])) {
            $responseCode = VmMath::parseIntBuiltinArg($frame->calledArgs[2], 'header', 3, 'response_code');
        }
        if (VmSapiHeaderGuard::headersAlreadySent($frame)) {
            VmSapiHeaderGuard::warnHeadersAlreadySent($frame);
            if (null !== $frame->returnVar) {
                $frame->returnVar->null();
            }

            return;
        }
        if (VmSapiHeaderGuard::headerLineContainsNewline($line)) {
            VmSapiHeaderGuard::warnHeaderNewline($frame);
            if (null !== $frame->returnVar) {
                $frame->returnVar->null();
            }

            return;
        }
        if (0 !== $responseCode) {
            ResponseContext::setStatus($responseCode);
        } elseif (0 === strncasecmp($line, 'Location:', 9) && ResponseContext::isHttpResponseCodeUnset()) {
            ResponseContext::setStatus(302);
        }
        ResponseContext::addHeader($line, $replace);
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if ([] === $args || \count($args) > 3) {
            throw new \LogicException('header() requires one to three arguments');
        }
        $line = self::jitHeaderArg($context, $args[0]);
        if (isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            HttpResponseCode::emitStandaloneStatusLine(
                $context,
                JitLongArg::lower($context, $args[2], 'header(): Argument #3 ($response_code)')
            );
        }
        $i32 = $context->getTypeFromString('int32');
        $replaceI32 = $i32->constInt(1, false);
        if (isset($args[1]) && !NamedOptionalCallArgs::isOmittedOptional($args[1])) {
            $replaceI32 = $context->builder->zExt(
                JitBoolArg::lower($context, $args[1], 'header(): Argument #2 ($replace)'),
                $i32
            );
        }
        JitPendingHeaders::add($context, $line, $replaceI32);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }

    /** Z_PARAM_STR — null TypeError on 8.4 forward profile (#19224, ext/standard/head.c). */
    private static function vmHeaderArg(Frame $frame): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, 0, 'header', 'header')->toString();
        }

        return VmString::coerceZparamStrBuiltinArg(
            $frame->calledArgs[0],
            'header',
            0,
            'header'
        );
    }

    private static function jitHeaderArg(Context $context, JITVariable $arg): Value
    {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'header',
                0,
                'header'
            );
        }

        return JitStringBuiltinArg::lowerZparamStr(
            $context,
            $arg,
            'header',
            0,
            'header'
        );
    }
}
