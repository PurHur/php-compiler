<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * vfscanf() — retired public API (#26758; was #6174).
 *
 * php-src only ships sscanf()/fscanf() (shared php_sscanf_internal). Class retained so
 * Module can re-enable behind CompilerVersion::supportsVfscanf() if ever needed; fscanf()
 * uses VmVfscanf / JitVfscanf helpers directly.
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
        // Z_PARAM_STR $format — TypeError under strict_types; soft-null otherwise (#30236).
        $format = VmString::trimFamilyStringArgForFrame($frame, 1, 'vfscanf', 1, 'format');
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
        return JitVfscanf::parse($context, 'vfscanf', ...$args);
    }
}
