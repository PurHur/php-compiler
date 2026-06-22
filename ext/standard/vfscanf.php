<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * vfscanf() — formatted stream input (php-src ext/standard/scanf.c; issue #6174).
 */
final class vfscanf extends Internal
{
    public function __construct()
    {
        parent::__construct('vfscanf');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError('vfscanf() expects at least 2 arguments, '.$argc.' given');
        }
        $handle = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[0]->resolveIndirect(),
            'vfscanf',
            1
        );
        $format = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'vfscanf', 1, 'format');
        $outVars = [];
        for ($i = 2; $i < $argc; ++$i) {
            $outVars[] = $frame->calledArgs[$i];
        }
        if (null === $frame->returnVar) {
            if ([] !== $outVars) {
                VmVfscanf::parse($handle, $format, $outVars);
            }

            return;
        }
        if ([] === $outVars) {
            $frame->returnVar->array(VmVfscanf::parseToArray($handle, $format));

            return;
        }
        $frame->returnVar->int(VmVfscanf::parse($handle, $format, $outVars));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitVfscanf::parse($context, ...$args);
    }
}
