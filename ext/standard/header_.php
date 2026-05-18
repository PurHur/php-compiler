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
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * header() for HTTP response headers (delegates to PHP; VM only).
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
        if (0 !== $responseCode) {
            \header($v->toString(), $replace, $responseCode);
        } else {
            \header($v->toString(), $replace);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('header() is not implemented for JIT in this compiler build');
    }
}
