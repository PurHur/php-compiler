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
            // php-src string.c PHP_FUNCTION(strtr) 3-arg: Z_PARAM_STR($from) / Z_PARAM_STRING($to)
            // — null DEP+coerce on 8.4 (not TypeError). Labels: array|string / ?string (#29308).
            $from = self::vmThreeArgSpanString($frame, 1, 'from', 'array|string');
            $to = self::vmThreeArgSpanString($frame, 2, 'to', '?string');
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
            // Soft-null outside strict_types (#29308). Strict $from cites array|string
            // (Z_PARAM_ARRAY_HT_OR_STR / stub; #31409) — not bare "string".
            $toExpected = $context->callerStrictTypes ? 'string' : '?string';

            return JitStrtr::translate(
                $context,
                JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'strtr', 0, 'string'),
                JitStringBuiltinArg::lower(
                    $context,
                    $args[1],
                    'strtr',
                    1,
                    'from',
                    'array|string',
                    null,
                    false,
                    false
                ),
                JitStringBuiltinArg::lower(
                    $context,
                    $args[2],
                    'strtr',
                    2,
                    'to',
                    $toExpected,
                    null,
                    false,
                    false
                ),
                $args[0],
                $args[1],
                $args[2]
            );
        }

        // 2-arg form without array replace_pairs — type path below translateArray.
        return JitStrtr::translateArray($context, $args[0], $args[1]);
    }

    /**
     * php-src ext/standard/string.c — two-arg strtr() expects array replace_pairs.
     *
     * Weak callers: Z_PARAM_ARRAY_HT_OR_STR coerces scalars then the C path hardcodes
     * "must be of type array, string given"; objects fail the union as array|string (#16772).
     * Strict callers: union TypeError cites array|string + actual (#16772 / #31409).
     */
    private static function twoArgSecondTypeError(Frame $frame, Variable $value): \TypeError
    {
        $value = $value->resolveIndirect();
        if (InternalStrictArg::isCallerStrict($frame)) {
            return new \TypeError(\sprintf(
                'strtr(): Argument #2 ($from) must be of type array|string, %s given',
                \PHPCompiler\VM\EnumCaseSupport::typeNameForTypeErrorActual($value)
            ));
        }
        if (Variable::TYPE_OBJECT === $value->type) {
            return new \TypeError(\sprintf(
                'strtr(): Argument #2 ($from) must be of type array|string, %s given',
                \PHPCompiler\VM\EnumCaseSupport::typeNameForTypeErrorActual($value)
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

    /**
     * Three-arg $from / $to — soft-null with Zend DEP labels; strict_types → TypeError (#29308).
     *
     * php-src: ext/standard/string.c PHP_FUNCTION(strtr); stub array|string $from, ?string $to.
     * Under strict, $from TypeError cites array|string (Z_PARAM_ARRAY_HT_OR_STR), not bare string (#31409).
     */
    private static function vmThreeArgSpanString(
        Frame $frame,
        int $argIndex,
        string $paramName,
        string $softExpectedType
    ): string {
        if (InternalStrictArg::isCallerStrict($frame)) {
            if ('array|string' === $softExpectedType) {
                $arg = $frame->calledArgs[$argIndex]->resolveIndirect();
                if (Variable::TYPE_STRING !== $arg->type) {
                    throw new \TypeError(\sprintf(
                        'strtr(): Argument #%d ($%s) must be of type array|string, %s given',
                        $argIndex + 1,
                        $paramName,
                        \PHPCompiler\VM\EnumCaseSupport::typeNameForTypeErrorActual($arg)
                    ));
                }

                return $arg->toString();
            }

            return InternalStrictArg::requireString($frame, $argIndex, 'strtr', $paramName)->toString();
        }

        return VmString::coerceStringBuiltinArg(
            $frame->calledArgs[$argIndex],
            'strtr',
            $argIndex,
            $paramName,
            $softExpectedType,
            false
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
