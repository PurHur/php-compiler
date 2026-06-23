<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
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
        $input = VmString::requireStringBuiltinArg($frame->calledArgs[0], 'sscanf', 0, 'string');
        $format = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'sscanf', 1, 'format');
        $outVars = [];
        for ($i = 2; $i < $argc; ++$i) {
            $outVars[] = $frame->calledArgs[$i];
        }
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
