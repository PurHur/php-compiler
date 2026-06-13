<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** bzcompress() — libbz2 via FFI (php-src ext/bz2/bz2.c; #3402). */
final class bzcompress extends Internal
{
    public function __construct()
    {
        parent::__construct('bzcompress');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('bzcompress() expects one to three arguments in this compiler build');
        }
        $source = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'bzcompress', 0, 'source');
        $blockSize = 4;
        if ($argc >= 2) {
            $blockSize = self::parseIntArg($frame->calledArgs[1], 'bzcompress', 1, 'blocksize');
        }
        $workFactor = 0;
        if (3 === $argc) {
            $workFactor = self::parseIntArg($frame->calledArgs[2], 'bzcompress', 2, 'workfactor');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmBz2Native::compress($source, $blockSize, $workFactor);
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
            throw new \LogicException('bzcompress() expects one to three arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $blockSize = $i64->constInt(4, false);
        $workFactor = $i64->constInt(0, false);
        if ($argc >= 2) {
            $blockSize = JitStrictIntArg::lower($context, $args[1], 'bzcompress', 1, 'blocksize');
        }
        if (3 === $argc) {
            $workFactor = JitStrictIntArg::lower($context, $args[2], 'bzcompress', 2, 'workfactor');
        }

        return JitBz2::compress(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'bzcompress', 0, 'source'),
            $blockSize,
            $workFactor
        );
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
