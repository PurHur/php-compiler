<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * fscanf() — formatted stream input with variadic by-ref targets (#3284, ext/standard/fscanf.c).
 */
final class fscanf extends Internal
{
    public function __construct()
    {
        parent::__construct('fscanf');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError('fscanf() expects at least 2 arguments, '.$argc.' given');
        }
        $handle = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[0]->resolveIndirect(),
            'fscanf',
            1
        );
        // Z_PARAM_STR $format — TypeError under strict_types; soft-null otherwise (#30236).
        $format = VmString::trimFamilyStringArgForFrame($frame, 1, 'fscanf', 1, 'format');
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
            $parsed = VmVfscanf::parseToArray($handle, $format);
            if (false === $parsed) {
                $frame->returnVar->bool(false);
            } elseif (null === $parsed) {
                $frame->returnVar->null();
            } else {
                $frame->returnVar->array($parsed);
            }

            return;
        }
        $parsed = VmVfscanf::parse($handle, $format, $outVars);
        if (false === $parsed) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($parsed);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitVfscanf::parse($context, 'fscanf', ...$args);
    }
}
