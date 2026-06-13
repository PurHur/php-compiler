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

/** bzdecompress() — libbz2 via FFI (php-src ext/bz2/bz2.c; #3402). */
final class bzdecompress extends Internal
{
    public function __construct()
    {
        parent::__construct('bzdecompress');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('bzdecompress() expects one or two arguments in this compiler build');
        }
        $source = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'bzdecompress', 0, 'source');
        $small = 0;
        if (2 === $argc) {
            $smallVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $smallVar->type) {
                throw new \TypeError(\sprintf(
                    'bzdecompress(): Argument #2 ($small) must be of type int, %s given',
                    match ($smallVar->type) {
                        Variable::TYPE_NULL => 'null',
                        Variable::TYPE_BOOLEAN => 'bool',
                        Variable::TYPE_DOUBLE => 'float',
                        Variable::TYPE_STRING => 'string',
                        Variable::TYPE_ARRAY => 'array',
                        Variable::TYPE_OBJECT => $smallVar->toObject()->class->name,
                        Variable::TYPE_RESOURCE => 'resource',
                        default => 'mixed',
                    }
                ));
            }
            $small = $smallVar->toInt();
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmBz2Native::decompress($source, $small);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('bzdecompress() expects one or two arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $small = $i64->constInt(0, false);
        if (2 === $argc) {
            $small = JitStrictIntArg::lower($context, $args[1], 'bzdecompress', 1, 'small');
        }

        return JitBz2::decompress(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'bzdecompress', 0, 'source'),
            $small
        );
    }
}
