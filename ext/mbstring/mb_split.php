<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_split() — multibyte regex split (php-src ext/mbstring/php_mbregex.c; #13367, #29811).
 */
final class mb_split extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_split');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(sprintf(
                'mb_split() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR $pattern / $string — caller strict_types → TypeError on null (#29811).
        $pattern = VmString::zparamStrBuiltinArgForFrame($frame, 0, 'mb_split', 0, 'pattern');
        if (null === $frame->returnVar) {
            return;
        }
        $string = VmString::zparamStrBuiltinArgForFrame($frame, 1, 'mb_split', 1, 'string');
        $limit = -1;
        if ($argc >= 3) {
            $limitVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $limitVar->type) {
                throw new \TypeError(sprintf(
                    'mb_split(): Argument #3 ($limit) must be of type int, %s given',
                    match ($limitVar->type) {
                        Variable::TYPE_NULL => 'null',
                        Variable::TYPE_BOOLEAN => 'bool',
                        Variable::TYPE_DOUBLE => 'float',
                        Variable::TYPE_STRING => 'string',
                        Variable::TYPE_ARRAY => 'array',
                        Variable::TYPE_OBJECT => $limitVar->toObject()->class->name,
                        default => 'mixed',
                    }
                ));
            }
            $limit = $limitVar->toInt();
        }

        $result = VmMbstring::split($pattern, $string, $limit);
        if (false === $result) {
            if (null !== VmMbstring::mbSplitRegexCompileError($pattern)) {
                VmMbstring::warnMbSplitRegexFailure($frame, $pattern);
            }
            BuiltinExecute::writeReturn($frame, static fn (Variable $ret) => $ret->bool(false));

            return;
        }

        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            $ret->array(MbstringState::hashTableFromStringList($result));
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('mb_split() requires two or three arguments');
        }

        // Compile-time null $pattern/$string under caller strict_types → TypeError (#29811).
        $patternIsNull = JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant;
        if ($patternIsNull && $context->callerStrictTypes) {
            JitInternalStrictArg::rejectNullString($context, $args[0], 'mb_split', 'pattern', 1);

            return self::foldFalse($context);
        }
        $stringIsNull = JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant;
        if ($stringIsNull && $context->callerStrictTypes) {
            JitInternalStrictArg::rejectNullString($context, $args[1], 'mb_split', 'string', 2);

            return self::foldFalse($context);
        }

        throw new \LogicException('mb_split() is not lowered for JIT/AOT in this compiler build');
    }

    private static function foldFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

        return JitValueBox::pointer($context, $slot);
    }
}
