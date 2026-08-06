<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zstd;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** zstd_compress_add() — streaming compress chunk (kjdev/php-ext-zstd; #27882). */
final class zstd_compress_add extends Internal
{
    public function __construct()
    {
        parent::__construct('zstd_compress_add');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError('zstd_compress_add() expects 2 to 3 arguments, '.$argc.' given');
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'zstd_compress_add', 1, 'data');
        $end = false;
        if (3 === $argc) {
            $endVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $endVar->type && Variable::TYPE_INTEGER !== $endVar->type) {
                throw new \TypeError('zstd_compress_add(): Argument #3 ($end) must be of type bool, '.self::typeName($endVar).' given');
            }
            $end = (bool) $endVar->toBool();
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmZstdContext::compressAdd($frame->calledArgs[0], $data, $end);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, \PHPCompiler\JIT\Variable ...$args): Value
    {
        throw new \LogicException('zstd_compress_add() is VM-only in this compiler build (#27882)');
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
