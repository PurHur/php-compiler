<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * zstd_compress_dict() — deprecated PECL alias (kjdev/php-ext-zstd; #27882).
 *
 * Dictionary frames are not implemented yet — returns false when $dict is non-empty.
 */
final class zstd_compress_dict extends Internal
{
    public function __construct()
    {
        parent::__construct('zstd_compress_dict');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError('zstd_compress_dict() expects 2 to 3 arguments, '.$argc.' given');
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'zstd_compress_dict', 0, 'data');
        $dict = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'zstd_compress_dict', 1, 'dict');
        $level = VmZstdContext::LEVEL_DEFAULT;
        if (3 === $argc) {
            $levelVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $levelVar->type) {
                throw new \TypeError('zstd_compress_dict(): Argument #3 ($level) must be of type int');
            }
            $level = $levelVar->toInt();
        }
        if (null === $frame->returnVar) {
            return;
        }
        if ('' !== $dict) {
            // No dictionary codec yet — PECL would apply $dict; fail closed.
            $frame->returnVar->bool(false);

            return;
        }
        $result = VmZstdNative::compress($data, $level);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, \PHPCompiler\JIT\Variable ...$args): Value
    {
        throw new \LogicException('zstd_compress_dict() is VM-only in this compiler build (#27882)');
    }
}
