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
 * highlight_file() — read file and emit syntax-highlighted HTML (VM: HighlightEngine, #4824).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/url.c PHP_FUNCTION(highlight_file)
 */
final class highlight_file extends Internal
{
    public function execute(Frame $frame): void
    {
        self::run($frame, $this->getName());
    }

    public static function run(Frame $frame, string $functionName): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException($functionName.'() expects 1 or 2 arguments in this compiler build');
        }
        $path = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            $functionName,
            0,
            'filename'
        );
        $return = false;
        if ($argc >= 2) {
            $return = VmHighlight::resolveReturnFlag($frame->calledArgs[1], $functionName);
        }
        $result = VmHighlight::highlightFile($path, $return);
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
        return JitHighlight::highlightFile($context, $this->getName(), ...$args);
    }
}
