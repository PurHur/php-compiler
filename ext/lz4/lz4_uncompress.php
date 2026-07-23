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

/** lz4_uncompress() — liblz4 via FFI (kjdev/php-ext-lz4; #22529). */
final class lz4_uncompress extends Internal
{
    public function __construct()
    {
        parent::__construct('lz4_uncompress');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(
                'lz4_uncompress() expects at least 1 argument, '.$argc.' given'
            );
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'lz4_uncompress', 0, 'data');
        $max = -1;
        $offset = 0;
        if ($argc >= 2) {
            $max = self::parseIntArg($frame->calledArgs[1], 'lz4_uncompress', 1, 'max');
        }
        if (3 === $argc) {
            $offset = self::parseIntArg($frame->calledArgs[2], 'lz4_uncompress', 2, 'offset');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmLz4Native::uncompress($data, $max, $offset);
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
            throw new \LogicException('lz4_uncompress() expects one to three arguments in this compiler build');
        }
        $max = $context->getTypeFromString('int64')->constInt(-1, true);
        $offset = $context->getTypeFromString('int64')->constInt(0, false);
        if ($argc >= 2) {
            $max = JitStrictIntArg::lower($context, $args[1], 'lz4_uncompress', 1, 'max');
        }
        if (3 === $argc) {
            $offset = JitStrictIntArg::lower($context, $args[2], 'lz4_uncompress', 2, 'offset');
        }

        return JitLz4::uncompress(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'lz4_uncompress', 0, 'data'),
            $max,
            $offset
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
