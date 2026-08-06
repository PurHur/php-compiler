<?php

declare(strict_types=1);

namespace PHPCompiler\ext\brotli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** brotli_compress_init() — streaming compress context (kjdev/php-ext-brotli; #27856). */
final class brotli_compress_init extends Internal
{
    public function __construct()
    {
        parent::__construct('brotli_compress_init');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \ArgumentCountError('brotli_compress_init() expects at most 2 arguments, '.$argc.' given');
        }
        $level = VmBrotliNative::DEFAULT_QUALITY;
        $mode = VmBrotliNative::MODE_GENERIC;
        if ($argc >= 1) {
            $level = self::requireInt($frame->calledArgs[0], 'brotli_compress_init', 0, 'level');
        }
        if (2 === $argc) {
            $mode = self::requireInt($frame->calledArgs[1], 'brotli_compress_init', 1, 'mode');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmBrotliContext::compressInit(VmReflection::requireContext($frame), $level, $mode);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, \PHPCompiler\JIT\Variable ...$args): Value
    {
        throw new \LogicException('brotli_compress_init() is VM-only in this compiler build (#27856)');
    }

    private static function requireInt(Variable $var, string $function, int $argIndex, string $paramName): int
    {
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
                    default => 'mixed',
                }
            ));
        }

        return $var->toInt();
    }
}
