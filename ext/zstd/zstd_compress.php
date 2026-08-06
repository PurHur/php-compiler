<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** zstd_compress() — pure PHP via VmZstdCore (php-src ext/zstd/zstd.c; #6382, #6387, #8869). */
final class zstd_compress extends Internal
{
    public function __construct()
    {
        parent::__construct('zstd_compress');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('zstd_compress() expects one to three arguments in this compiler build');
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'zstd_compress', 0, 'data');
        $level = VmZstdContext::LEVEL_DEFAULT;
        if ($argc >= 2) {
            $levelVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $levelVar->type) {
                throw new \LogicException('zstd_compress() level must be an integer in this compiler build');
            }
            $level = $levelVar->toInt();
        }
        if (3 === $argc) {
            $dictVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $dictVar->type) {
                // Optional $dict — dictionary frames not implemented yet (#27882).
                if (null === $frame->returnVar) {
                    return;
                }
                $frame->returnVar->bool(false);

                return;
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmZstdNative::compress($data, $level);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('zstd_compress() expects one to three arguments in this compiler build');
        }
        $level = JitZstd::defaultLevel($context);
        if ($argc >= 2) {
            $level = JitStrictIntArg::lowerLevel($context, $args[1], 'zstd_compress');
        }
        if (3 === $argc) {
            throw new \LogicException('zstd_compress() dictionary argument is VM-only in this compiler build (#27882)');
        }

        return JitZstd::compress(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'zstd_compress', 0, 'data'),
            $level
        );
    }
}
