<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** localtime() — struct tm breakdown (VM VmDate; JIT LocaltimeJitHelper, #6812, #9181). */
final class localtime extends Internal
{
    public function __construct()
    {
        parent::__construct('localtime');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \ArgumentCountError('localtime() expects at most 2 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $timestamp = null;
        if ($argc >= 1) {
            $tsVar = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_INTEGER === $tsVar->type) {
                $timestamp = $tsVar->toInt();
            } elseif (Variable::TYPE_NULL !== $tsVar->type) {
                throw new \TypeError(self::timestampTypeError($tsVar->type));
            }
        }
        $associative = false;
        if (2 === $argc) {
            $associative = self::parseAssociativeArg($frame->calledArgs[1]);
        }
        $frame->returnVar->array(VmDate::localtimeBreakdown($timestamp, $associative));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 2) {
            throw new \LogicException('localtime() accepts at most two arguments in this compiler build');
        }
        $associative = $context->constantFromBool(false);
        if (isset($args[1])) {
            $associative = JitBoolArg::lower(
                $context,
                $args[1],
                'localtime(): Argument #2 ($associative)'
            );
        }

        return JitLocaltime::invoke($context, $args[0] ?? null, $associative);
    }

    private static function parseAssociativeArg(Variable $var): bool
    {
        $var = $var->resolveIndirect();
        switch ($var->type) {
            case Variable::TYPE_BOOLEAN:
                return $var->toBool();
            case Variable::TYPE_INTEGER:
                return 0 !== $var->toInt();
            case Variable::TYPE_FLOAT:
                return 0.0 !== $var->toFloat();
            case Variable::TYPE_NULL:
                return false;
            case Variable::TYPE_STRING:
                $literal = $var->toString();
                if ('' === $literal || '0' === $literal) {
                    return false;
                }
                $lower = strtolower($literal);
                if (\in_array($lower, ['false', 'off', 'no'], true)) {
                    return false;
                }
                if (\in_array($lower, ['1', 'true', 'on', 'yes'], true)) {
                    return true;
                }

                return true;
            case Variable::TYPE_ARRAY:
                throw new \TypeError(
                    'localtime(): Argument #2 ($associative) must be of type bool, array given'
                );
            case Variable::TYPE_OBJECT:
            case Variable::TYPE_ENUM_CASE:
                throw new \TypeError(
                    'localtime(): Argument #2 ($associative) must be of type bool, object given'
                );
            default:
                throw new \TypeError(
                    'localtime(): Argument #2 ($associative) must be of type bool, '
                    .self::vmTypeName($var->type).' given'
                );
        }
    }

    private static function timestampTypeError(int $type): string
    {
        return \sprintf(
            'localtime(): Argument #1 ($timestamp) must be of type ?int, %s given',
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
