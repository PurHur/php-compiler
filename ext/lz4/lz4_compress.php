<?php

declare(strict_types=1);

namespace PHPCompiler\ext\lz4;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** lz4_compress() — liblz4 via FFI (kjdev/php-ext-lz4; #22529). */
final class lz4_compress extends Internal
{
    public function __construct()
    {
        parent::__construct('lz4_compress');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'lz4_compress() expects at least 1 argument, '.$argc.' given'
            );
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'lz4_compress', 0, 'data');
        $level = 0;
        if (2 === $argc) {
            $level = self::parseIntArg($frame->calledArgs[1], 'lz4_compress', 1, 'level');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmLz4Native::compress($data, $level);
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
            throw new \LogicException('lz4_compress() expects one or two arguments in this compiler build');
        }
        $level = JitLz4::defaultLevel($context);
        if (2 === $argc) {
            $level = JitStrictIntArg::lower($context, $args[1], 'lz4_compress', 1, 'level');
        }

        return JitLz4::compress(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'lz4_compress', 0, 'data'),
            $level
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
