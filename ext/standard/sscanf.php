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
 * sscanf() — parse string into variables by reference (issue #3190, php-src ext/standard/sscanf.c).
 */
final class sscanf extends Internal
{
    public function __construct()
    {
        parent::__construct('sscanf');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \LogicException('sscanf() requires at least two arguments');
        }
        $strVar = $frame->calledArgs[0]->resolveIndirect();
        $fmtVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $strVar->type || Variable::TYPE_STRING !== $fmtVar->type) {
            throw new \LogicException('sscanf() string and format must be strings in this compiler build');
        }
        $outVars = [];
        for ($i = 2; $i < $argc; ++$i) {
            $outVars[] = $frame->calledArgs[$i];
        }
        $input = $strVar->toString();
        $format = $fmtVar->toString();
        if (null === $frame->returnVar) {
            if ([] !== $outVars) {
                VmSscanf::parse($input, $format, $outVars);
            }

            return;
        }
        if ([] === $outVars) {
            $frame->returnVar->array(VmSscanf::parseToArray($input, $format));

            return;
        }
        $count = VmSscanf::parse($input, $format, $outVars);
        $frame->returnVar->int($count);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitSscanf::parse($context, ...$args);
    }
}
