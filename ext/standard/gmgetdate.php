<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** gmgetdate() — UTC associative date/time breakdown (VM VmDate; JIT StringGmgetdate, #7001). */
final class gmgetdate extends Internal
{
    public function __construct()
    {
        parent::__construct('gmgetdate');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError('gmgetdate() accepts at most 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $timestamp = null;
        if (1 === $argc) {
            $tsVar = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_INTEGER === $tsVar->type) {
                $timestamp = $tsVar->toInt();
            } elseif (Variable::TYPE_NULL !== $tsVar->type) {
                throw new \TypeError(self::timestampTypeError($tsVar->type));
            }
        }
        $frame->returnVar->array(VmDate::gmgetdate($timestamp));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('gmgetdate() accepts at most one argument in this compiler build');
        }

        return JitGmgetdate::invoke($context, $args[0] ?? null);
    }

    private static function timestampTypeError(int $type): string
    {
        return \sprintf(
            'gmgetdate(): Argument #1 ($timestamp) must be of type ?int, %s given',
            self::vmTypeName($type)
        );
    }

    private static function vmTypeName(int $type): string
    {
        return match ($type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_ENUM_CASE => 'object',
            default => 'mixed',
        };
    }
}
