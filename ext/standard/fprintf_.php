<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * fprintf() — formatted write to stream (php-src ext/standard/formatted_print.c; #3301).
 *
 * Z_PARAM_STR $format: null TypeError on PHP_COMPILER_PROFILE=8.4 (#20197).
 */
final class fprintf_ extends Internal
{
    public function __construct()
    {
        parent::__construct('fprintf');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError('fprintf() expects at least 2 arguments, '.$argc.' given');
        }
        $handle = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[0]->resolveIndirect(),
            'fprintf',
            1
        );
        // Z_PARAM_STR — null TypeError on PROFILE=8.4 (#20197, formatted_print.c).
        $format = VmString::zparamStrBuiltinArgForFrame($frame, 1, 'fprintf', 1, 'format');
        $argsHt = new HashTable();
        for ($i = 2; $i < $argc; ++$i) {
            $argsHt->append($frame->calledArgs[$i]->resolveIndirect());
        }
        $argsVar = new Variable();
        $argsVar->array($argsHt);
        $written = VmVprintf::vfprintf($handle, $format, $argsVar, $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($written);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitFprintf::format($context, ...$args);
    }
}
