<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** gzinflate() — inflate zlib-compressed data (ext/zlib/zlib.c parity, issue #3194). */
final class gzinflate extends Internal
{
    public function __construct()
    {
        parent::__construct('gzinflate');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('gzinflate() expects one or two arguments in this compiler build');
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'gzinflate', 0, 'data');
        $maxLength = 0;
        if (2 === $argc) {
            $maxVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $maxVar->type) {
                throw new \LogicException('gzinflate() max_length must be an integer in this compiler build');
            }
            $maxLength = $maxVar->toInt();
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmZlib::gzinflate($data, $maxLength);
        if (false === $result) {
            VmZlib::triggerWarning($frame, 'gzinflate(): data error');

            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('gzinflate() expects one or two arguments in this compiler build');
        }
        $maxLength = $context->getTypeFromString('int64')->constInt(0, false);
        if (2 === $argc) {
            $maxLength = JitLongArg::lower($context, $args[1], 'gzinflate() max_length');
        }

        return JitZlib::inflate(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'gzinflate', 0, 'data'),
            $maxLength
        );
    }
}
