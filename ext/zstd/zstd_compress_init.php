<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** zstd_compress_init() — streaming compress context (kjdev/php-ext-zstd; #27882). */
final class zstd_compress_init extends Internal
{
    public function __construct()
    {
        parent::__construct('zstd_compress_init');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \ArgumentCountError('zstd_compress_init() expects at most 2 arguments, '.$argc.' given');
        }
        $level = VmZstdContext::LEVEL_DEFAULT;
        if ($argc >= 1) {
            $levelVar = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $levelVar->type) {
                throw new \TypeError('zstd_compress_init(): Argument #1 ($level) must be of type int, '.self::typeName($levelVar).' given');
            }
            $level = $levelVar->toInt();
        }
        // Optional $dict ignored until dictionary codec lands (#27882).
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmZstdContext::compressInit(VmReflection::requireContext($frame), $level);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, \PHPCompiler\JIT\Variable ...$args): Value
    {
        throw new \LogicException('zstd_compress_init() is VM-only in this compiler build (#27882)');
    }

    private static function typeName(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_DOUBLE => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => $var->toObject()->class->name,
            default => 'mixed',
        };
    }
}
