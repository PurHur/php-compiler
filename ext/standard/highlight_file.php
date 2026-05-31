<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * highlight_file() — read file and emit syntax-highlighted HTML (VM host Zend).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/url.c PHP_FUNCTION(highlight_file)
 */
final class highlight_file extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('highlight_file() expects 1 or 2 arguments in this compiler build');
        }
        $pathVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $pathVar->type) {
            throw new \LogicException('highlight_file() expects string for argument 1 in this compiler build');
        }
        $return = false;
        if ($argc >= 2) {
            $retVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $retVar->type) {
                throw new \LogicException('highlight_file() expects bool for argument 2 in this compiler build');
            }
            $return = $retVar->toBool();
        }
        $result = VmHighlight::highlightFile($pathVar->toString(), $return);
        if (null === $frame->returnVar) {
            return;
        }
        if ($return) {
            if (false === $result) {
                $frame->returnVar->bool(false);

                return;
            }
            $frame->returnVar->string((string) $result);

            return;
        }
        $frame->returnVar->bool((bool) $result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('highlight_file() is VM only in this compiler build');
    }
}
