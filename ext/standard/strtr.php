<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** strtr() — byte translation table or replace_pairs array (JIT/AOT via JitStrtr). */
final class strtr extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.c — ArgumentCountError (#23164).
        $this->requireArgCountRange($frame, 'strtr', 2, 3);
        $argc = \count($frame->calledArgs);
        if (2 === $argc) {
            $string = self::vmStringArg($frame, 0, 'string');
            $pairs = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $pairs->type) {
                throw self::twoArgSecondTypeError($frame, $pairs);
            }
            // php_strtr_array() — lazy zval_get_tmp_string on values (#28978).
            $result = VmString::strtrArrayFromHashTable($string, $pairs->toArray(), $frame);
            BuiltinExecute::writeReturn($frame, static fn (Variable $ret) => $ret->string($result));

            return;
        }
        if (3 === $argc) {
            $string = self::vmStringArg($frame, 0, 'string');
            $from = VmString::coerceZparamStrBuiltinArg(
                $frame->calledArgs[1],
                'strtr',
                1,
                'from'
            );
            $to = VmString::coerceZparamStrBuiltinArg(
                $frame->calledArgs[2],
                'strtr',
                2,
                'to'
            );
            $result = VmString::strtr($string, $from, $to);
            BuiltinExecute::writeReturn($frame, static fn (Variable $ret) => $ret->string($result));

            return;
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireArgCountRangeJit($context, $args, 'strtr', 2, 3)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        if (\count($args) >= 2 && self::isReplacePairsArg($args[1])) {
            return JitStrtr::translateArray($context, $args[0], $args[1]);
        }
        if (3 === \count($args)) {
            return JitStrtr::translate(
                $context,
                JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'strtr', 0, 'string'),
                JitStringBuiltinArg::lowerZparamStr($context, $args[1], 'strtr', 1, 'from'),
                JitStringBuiltinArg::lowerZparamStr($context, $args[2], 'strtr', 2, 'to'),
                $args[0],
                $args[1],
                $args[2]
            );
        }

        // 2-arg form without array replace_pairs — type path below translateArray.
        return JitStrtr::translateArray($context, $args[0], $args[1]);
    }

    /**
     * php-src ext/standard/string.c — two-arg strtr() expects array replace_pairs; Zend
     * labels arg #2 $from and reports coercible scalars as "string given" (#16772).
     */
    private static function twoArgSecondTypeError(Frame $frame, Variable $value): \TypeError
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_OBJECT === $value->type) {
            return new \TypeError(\sprintf(
                'strtr(): Argument #2 ($from) must be of type array|string, %s given',
                $value->toObject()->class->name
            ));
        }
        if (InternalStrictArg::isCallerStrict($frame)) {
            return new \TypeError(\sprintf(
                'strtr(): Argument #2 ($from) must be of type array|string, %s given',
                VmParseStr::zendTypeLabel($value)
            ));
        }

        return new \TypeError('strtr(): Argument #2 ($from) must be of type array, string given');
    }

    /** Z_PARAM_STR — Zend 8.4 DEP+coerces null (#21207, ext/standard/string.c). */
    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'strtr', $paramName)->toString();
        }

        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'strtr',
            $argIndex,
            $paramName
        );
    }

    private static function isReplacePairsArg(JITVariable $arg): bool
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return true;
        }
        if (0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            return true;
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return true;
        }

        return false;
    }
}
