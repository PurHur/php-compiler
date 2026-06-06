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

/** gzencode() — gzip-encoded string (ext/zlib/zlib.c parity, issue #3194). */
final class gzencode extends Internal
{
    public function __construct()
    {
        parent::__construct('gzencode');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('gzencode() expects one to three arguments in this compiler build');
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'gzencode', 0, 'data');
        $level = -1;
        $encoding = \ZLIB_ENCODING_GZIP;
        if ($argc >= 2) {
            $levelVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $levelVar->type) {
                throw new \LogicException('gzencode() level must be an integer in this compiler build');
            }
            $level = $levelVar->toInt();
        }
        if (3 === $argc) {
            $encVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $encVar->type) {
                throw new \LogicException('gzencode() encoding must be an integer in this compiler build');
            }
            $encoding = $encVar->toInt();
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmZlib::gzencode($data, $level, $encoding);
        if (false === $result) {
            VmZlib::triggerWarning($frame, 'gzencode(): data error');

            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('gzencode() expects one to three arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $level = $i64->constInt(-1, true);
        $encoding = $i64->constInt(\ZLIB_ENCODING_GZIP, false);
        if ($argc >= 2) {
            $level = JitLongArg::lower($context, $args[1], 'gzencode() level');
        }
        if (3 === $argc) {
            $encoding = JitLongArg::lower($context, $args[2], 'gzencode() encoding');
        }

        return JitZlib::encode(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'gzencode', 0, 'data'),
            $level,
            $encoding
        );
    }
}
