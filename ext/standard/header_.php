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
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
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
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('header() only supports string header lines in this compiler build');
        }
        $replace = true;
        $responseCode = 0;
        if ($argc >= 2) {
            $replaceVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $replaceVar->type) {
                throw new \LogicException('header() replace argument must be a boolean in this compiler build');
            }
            $replace = $replaceVar->toBool();
        }
        if (3 === $argc) {
            $codeVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $codeVar->type) {
                throw new \LogicException('header() response_code must be an integer in this compiler build');
            }
            $responseCode = $codeVar->toInt();
        }
        $line = $v->toString();
        if (0 !== $responseCode) {
            \header($line, $replace, $responseCode);
            ResponseContext::setStatus($responseCode);
        } else {
            \header($line, $replace);
        }
        ResponseContext::addHeader($line, $replace);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('header() requires one to three arguments');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('header() only supports string header lines in this compiler build');
        }
        $line = $context->helper->loadValue($args[0]);
        if ($argc >= 2 && JITVariable::TYPE_NATIVE_BOOL !== $args[1]->type) {
            throw new \LogicException('header() replace argument must be a boolean in this compiler build');
        }
        if (3 === $argc) {
            if (JITVariable::TYPE_NATIVE_LONG !== $args[2]->type) {
                throw new \LogicException('header() response_code must be an integer in this compiler build');
            }
            HttpResponseCode::emitStandaloneStatusLine($context, $context->helper->loadValue($args[2]));
        }
        JitHeader::emit($context, $line);

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
