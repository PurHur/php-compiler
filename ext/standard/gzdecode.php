<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** gzdecode() — decode gzip-encoded string (ext/zlib/zlib.c parity, issue #3194). */
final class gzdecode extends Internal
{
    public function __construct()
    {
        parent::__construct('gzdecode');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('gzdecode() expects one or two arguments in this compiler build');
        }
        $data = VmZlibArg::resolveDataString($frame, 'gzdecode');
        $maxLength = 0;
        if (2 === $argc) {
            $maxLength = VmZlibArg::requireInt($frame->calledArgs[1], 'gzdecode', 2, 'max_length');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmZlib::gzdecode($data, $maxLength);
        if (false === $result) {
            VmZlib::triggerWarning($frame, 'gzdecode(): data error');

            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('gzdecode() expects one or two arguments in this compiler build');
        }
        $maxLength = $context->getTypeFromString('int64')->constInt(0, false);
        if (2 === $argc) {
            $maxLength = JitStrictIntArg::lower($context, $args[1], 'gzdecode', 2, 'max_length');
        }

        return JitZlib::decode(
            $context,
            JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'gzdecode', 0, 'data'),
            $maxLength
        );
    }
}
