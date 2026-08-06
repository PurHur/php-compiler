<?php

declare(strict_types=1);

namespace PHPCompiler\ext\lz4;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** lz4_compress_frame() — LZ4F frame compress (kjdev/php-ext-lz4; #27883). */
final class lz4_compress_frame extends Internal
{
    public function __construct()
    {
        parent::__construct('lz4_compress_frame');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 4) {
            throw new \ArgumentCountError(
                'lz4_compress_frame() expects at least 1 argument, '.$argc.' given'
            );
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'lz4_compress_frame', 0, 'data');
        $level = 0;
        $maxBlockSize = 0;
        $checksums = 0;
        if ($argc >= 2) {
            $level = self::parseIntArg($frame->calledArgs[1], 'lz4_compress_frame', 1, 'level');
        }
        if ($argc >= 3) {
            $maxBlockSize = self::parseIntArg($frame->calledArgs[2], 'lz4_compress_frame', 2, 'max_block_size');
        }
        if (4 === $argc) {
            $checksums = self::parseIntArg($frame->calledArgs[3], 'lz4_compress_frame', 3, 'checksums');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmLz4Native::compressFrame($data, $level, $maxBlockSize, $checksums);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, \PHPCompiler\JIT\Variable ...$args): Value
    {
        throw new \LogicException('lz4_compress_frame() is VM-only in this compiler build (#27883)');
    }

    private static function parseIntArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName,
    ): int {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $function,
                $argIndex + 1,
                $paramName,
                match ($var->type) {
                    Variable::TYPE_NULL => 'null',
                    Variable::TYPE_BOOLEAN => 'bool',
                    Variable::TYPE_DOUBLE => 'float',
                    Variable::TYPE_STRING => 'string',
                    Variable::TYPE_ARRAY => 'array',
                    Variable::TYPE_OBJECT => $var->toObject()->class->name,
                    Variable::TYPE_RESOURCE => 'resource',
                    default => 'mixed',
                }
            ));
        }

        return $var->toInt();
    }
}
