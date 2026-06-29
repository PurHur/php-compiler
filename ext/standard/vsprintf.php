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
 * vsprintf() — format string + array of values (issue #3190, php-src ext/standard/sprintf.c).
 */
final class vsprintf extends Internal
{
    public function __construct()
    {
        parent::__construct('vsprintf');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('vsprintf() requires exactly two arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $fmtVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $fmtVar->type) {
            throw new \LogicException('vsprintf() format must be a string in this compiler build');
        }
        $argsVar = $frame->calledArgs[1]->resolveIndirect();
        VmVprintf::requireValuesArray($argsVar, 'vsprintf');
        $values = [];
        foreach ($argsVar->toArray()->iterate(true) as $element) {
            $values[] = $element->resolveIndirect();
        }
        $frame->returnVar->string(VmSprintf::format($fmtVar->toString(), $values, $frame));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitVsprintf::format($context, ...$args);
    }
}
