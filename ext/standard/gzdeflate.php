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

/** gzdeflate() — raw deflate (ext/zlib/zlib.c parity, issue #3194). */
final class gzdeflate extends Internal
{
    public function __construct()
    {
        parent::__construct('gzdeflate');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('gzdeflate() expects one to three arguments in this compiler build');
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'gzdeflate', 0, 'data');
        $level = -1;
        $encoding = \ZLIB_ENCODING_RAW;
        if ($argc >= 2) {
            $levelVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $levelVar->type) {
                throw new \LogicException('gzdeflate() level must be an integer in this compiler build');
            }
            $level = $levelVar->toInt();
        }
        if (3 === $argc) {
            $encVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $encVar->type) {
                throw new \LogicException('gzdeflate() encoding must be an integer in this compiler build');
            }
            $encoding = $encVar->toInt();
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmZlib::gzdeflate($data, $level, $encoding);
        if (false === $result) {
            VmZlib::triggerWarning($frame, 'gzdeflate(): data error');

            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('gzdeflate() expects one to three arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $level = $i64->constInt(-1, true);
        $encoding = $i64->constInt(\ZLIB_ENCODING_RAW, false);
        if ($argc >= 2) {
            $level = JitLongArg::lower($context, $args[1], 'gzdeflate() level');
        }
        if (3 === $argc) {
            $encoding = JitLongArg::lower($context, $args[2], 'gzdeflate() encoding');
        }

        return JitZlib::deflate(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'gzdeflate', 0, 'data'),
            $level,
            $encoding
        );
    }
}
