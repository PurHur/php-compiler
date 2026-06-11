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

/** zlib_encode() — one-shot zlib/gzip/deflate compress (ext/zlib/zlib.c, issue #6288). */
final class zlib_encode extends Internal
{
    public function __construct()
    {
        parent::__construct('zlib_encode');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('zlib_encode() expects two or three arguments in this compiler build');
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'zlib_encode', 0, 'data');
        $encVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $encVar->type) {
            throw new \LogicException('zlib_encode() encoding must be an integer in this compiler build');
        }
        $encoding = $encVar->toInt();
        self::assertValidEncoding($encoding);
        $level = -1;
        if (3 === $argc) {
            $levelVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $levelVar->type) {
                throw new \LogicException('zlib_encode() level must be an integer in this compiler build');
            }
            $level = $levelVar->toInt();
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmZlib::zlib_encode($data, $encoding, $level);
        if (false === $result) {
            VmZlib::triggerWarning($frame, 'zlib_encode(): data error');

            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('zlib_encode() expects two or three arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $level = $i64->constInt(-1, true);
        if (3 === $argc) {
            $level = JitLongArg::lower($context, $args[2], 'zlib_encode() level');
        }

        return JitZlib::zlibEncode(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'zlib_encode', 0, 'data'),
            JitLongArg::lower($context, $args[1], 'zlib_encode() encoding'),
            $level
        );
    }

    private static function assertValidEncoding(int $encoding): void
    {
        if (
            \ZLIB_ENCODING_RAW === $encoding
            || \ZLIB_ENCODING_DEFLATE === $encoding
            || \ZLIB_ENCODING_GZIP === $encoding
            || 65534 === $encoding
            || 65535 === $encoding
            || 16 === $encoding
        ) {
            return;
        }

        throw new \ValueError('zlib_encode(): Argument #2 ($encoding) must be one of ZLIB_ENCODING_RAW, ZLIB_ENCODING_GZIP, or ZLIB_ENCODING_DEFLATE');
    }
}
