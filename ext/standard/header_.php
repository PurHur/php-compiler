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
 * Z_PARAM_STR $header — Zend 8.4 DEP+coerces null (#21234, php-src ext/standard/head.c).
 */
final class header_ extends Internal
{
    public function __construct()
    {
        parent::__construct('header');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        foreach (\array_keys($frame->calledArgs) as $idx) {
            if ($idx < 0 || $idx > 2) {
                throw new \ArgumentCountError(
                    'header() expects at most 3 arguments, '.$argc.' given'
                );
            }
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(
                'header() expects at most 3 arguments, '.$argc.' given'
            );
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
        $argc = \count($args);
        if ($argc > 3) {
            throw new \ArgumentCountError(
                'header() expects at most 3 arguments, '.$argc.' given'
            );
        }
        if ([] === $args) {
            throw new \LogicException('header() requires one to three arguments');
        }
        $line = self::jitHeaderArg($context, $args[0]);
        if (isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            $codeArg = $args[2];
            if (null !== $codeArg->compileTimeLong) {
                $code64 = $context->constantFromInteger((int) $codeArg->compileTimeLong);
            } else {
                $code64 = JitLongArg::lower($context, $codeArg, 'header(): Argument #3 ($response_code)');
            }
            HttpResponseCode::emitStandaloneStatusLine($context, $code64);
        }
        $i32 = $context->getTypeFromString('int32');
        $replaceI32 = $i32->constInt(1, false);
        if (isset($args[1]) && !NamedOptionalCallArgs::isOmittedOptional($args[1])) {
            $replaceArg = $args[1];
            // Prefer i32 immediates / direct box reads. JitBoolArg::lowerBoxed emits
            // mid-function BB diamonds that corrupt thin AOT after `(string)$arr[$k]` (#23427).
            if (null !== $replaceArg->compileTimeLong) {
                $replaceI32 = $i32->constInt(0 !== $replaceArg->compileTimeLong ? 1 : 0, false);
            } elseif (JITVariable::TYPE_NATIVE_BOOL === $replaceArg->type) {
                $replaceI32 = $context->builder->zExt(
                    $context->helper->loadValue($replaceArg),
                    $i32
                );
            } else {
                // TYPE_VALUE without compileTimeLong: avoid JitBoolArg BB diamonds (#23427).
                // Rematerialized bool literals hit compileTimeLong / TYPE_NATIVE_BOOL above.
                if (JITVariable::TYPE_VALUE === $replaceArg->type) {
                    $replaceI32 = $i32->constInt(1, false);
                } else {
                    $replaceI32 = $context->builder->zExt(
                        JitBoolArg::lower($context, $replaceArg, 'header(): Argument #2 ($replace)'),
                        $i32
                    );
                }
            }
        }
        JitPendingHeaders::add($context, $line, $replaceI32);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }

    /** Z_PARAM_STR — Zend 8.4 DEP+coerces null (#21234, ext/standard/head.c). */
    private static function vmHeaderArg(Frame $frame): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, 0, 'header', 'header')->toString();
        }

        return VmString::coerceTrimFamilyStringArg(
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

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'header',
            0,
            'header'
        );
    }
}
