<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\JIT\UsortCallbackPolicy;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/** usort/uasort/uksort and array_u* null callback → TypeError (ext/standard/array.c; #10624, #10799, #10785). */
final class VmArraySortCallback
{
    /**
     * Reject undefined string callbacks before strcmp/strcasecmp fast-path deferral (#13273).
     */
    public static function rejectInvalidStringCallback(
        Frame $frame,
        Variable $callback,
        string $function,
        int $argNum = 2
    ): void {
        $callback = $callback->resolveIndirect();
        if (Variable::TYPE_STRING !== $callback->type) {
            return;
        }
        $name = $callback->toString();
        if (UsortCallbackPolicy::isVmSupportedName($name)) {
            return;
        }
        if (null === $frame->vmContext) {
            throw new \LogicException($function.'() requires VM context in this compiler build');
        }
        if (!VmCallable::isCallable($frame->vmContext, $callback)) {
            throw new \TypeError(self::invalidStringCallbackTypeError($function, $argNum, $name));
        }
    }

    /**
     * Whether callback is a strcmp-family string builtin (fast path; #10968).
     */
    public static function isStrcmpFamilyCallback(Variable $callback): bool
    {
        $callback = $callback->resolveIndirect();

        return Variable::TYPE_STRING === $callback->type
            && UsortCallbackPolicy::isVmSupportedName($callback->toString());
    }

    /**
     * Require a Zend-callable comparator for usort/uasort/uksort (#23550, #25712)
     * and array_u* (#25736). Closures, invokables, array callables, and user-defined
     * function names are accepted.
     *
     * @param string|null $paramName Null omits " ($name)" — Zend array_udiff family.
     */
    public static function requireVmCallable(
        Frame $frame,
        Variable $callback,
        string $function,
        int $argNum = 2,
        ?string $paramName = 'callback'
    ): void {
        $callback = $callback->resolveIndirect();
        if (self::isStrcmpFamilyCallback($callback) || VmClosureCall::isClosure($callback)) {
            return;
        }
        if (null === $frame->vmContext) {
            throw new \LogicException($function.'() requires VM context in this compiler build');
        }
        if (!VmCallable::isCallable($frame->vmContext, $callback, false, null, $frame)) {
            VmCallable::throwIfInaccessibleMethodCallback(
                $frame->vmContext,
                $callback,
                $function,
                $argNum,
                $frame,
                false,
                $paramName
            );
            throw new \TypeError(self::invalidCallbackTypeError($function, $argNum, $paramName));
        }
    }

    /**
     * Deep-copy operands then invoke any VM callable (php-src php_usort_compare; #23550, #25712).
     * Passes the caller frame so private/protected comparators stay visible after validation.
     */
    public static function invokeCompare(
        Context $context,
        Variable $callback,
        Variable $a,
        Variable $b,
        ?Frame $scopeFrame = null,
        string $function = 'usort'
    ): int {
        $copyA = new Variable();
        $copyA->duplicateFrom($a);
        $copyB = new Variable();
        $copyB->duplicateFrom($b);
        $result = VmCallable::invokeAsWithScope(
            $function,
            $context,
            $scopeFrame,
            $callback,
            $copyA,
            $copyB
        );

        return VmClosureCall::coerceUserSortCallbackResult($result);
    }

    /**
     * @param list<Variable> $values
     */
    public static function sortVariableValues(
        Context $context,
        array &$values,
        Variable $callback,
        bool $descending = false,
        ?Frame $scopeFrame = null,
        string $function = 'usort'
    ): void {
        // php-src PHP_ARRAY_CMP_FUNC_BACKUP — once-per-sort bool deprecation (#29089).
        VmClosureCall::beginUserSort($function);
        $cmp = static function (Variable $a, Variable $b) use (
            $context,
            $callback,
            $descending,
            $scopeFrame,
            $function
        ): int {
            $result = self::invokeCompare($context, $callback, $a, $b, $scopeFrame, $function);

            return $descending ? -$result : $result;
        };
        ZendSort::sort($values, $cmp);
    }

    /**
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByKey(
        Context $context,
        array &$pairs,
        Variable $callback,
        bool $descending = false,
        ?Frame $scopeFrame = null,
        string $function = 'uksort'
    ): void {
        VmClosureCall::beginUserSort($function);
        $cmp = static function (array $a, array $b) use (
            $context,
            $callback,
            $descending,
            $scopeFrame,
            $function
        ): int {
            $result = self::invokeCompare($context, $callback, $a[0], $b[0], $scopeFrame, $function);

            return $descending ? -$result : $result;
        };
        ZendSort::sort($pairs, $cmp);
    }

    /**
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByValue(
        Context $context,
        array &$pairs,
        Variable $callback,
        bool $descending = false,
        ?Frame $scopeFrame = null,
        string $function = 'uasort'
    ): void {
        VmClosureCall::beginUserSort($function);
        $cmp = static function (array $a, array $b) use (
            $context,
            $callback,
            $descending,
            $scopeFrame,
            $function
        ): int {
            $result = self::invokeCompare($context, $callback, $a[1], $b[1], $scopeFrame, $function);

            return $descending ? -$result : $result;
        };
        ZendSort::sort($pairs, $cmp);
    }

    public static function invalidCallbackTypeError(
        string $function,
        int $argNum = 2,
        ?string $paramName = 'callback'
    ): string {
        $paramPart = null !== $paramName ? ' ($'.$paramName.')' : '';

        return \sprintf(
            '%s(): Argument #%d%s must be a valid callback, no array or string given',
            $function,
            $argNum,
            $paramPart
        );
    }

    public static function invalidStringCallbackTypeError(
        string $function,
        int $argNum,
        string $name,
        ?string $paramName = 'callback'
    ): string {
        $paramPart = null !== $paramName ? ' ($'.$paramName.')' : '';

        return \sprintf(
            '%s(): Argument #%d%s must be a valid callback, function "%s" not found or invalid function name',
            $function,
            $argNum,
            $paramPart,
            $name
        );
    }

    public static function requireCallback(
        Variable $callback,
        string $function,
        int $argNum = 2,
        ?string $paramName = 'callback'
    ): void {
        if (Variable::TYPE_NULL === $callback->resolveIndirect()->type) {
            $paramPart = null !== $paramName ? ' ($'.$paramName.')' : '';
            throw new \TypeError(
                $function.'(): Argument #'.$argNum.$paramPart.' must be a valid callback, no array or string given'
            );
        }
    }

    /**
     * array_diff_uassoc()/array_intersect_uassoc() missing comparator (#10785, php-src array.c).
     */
    public static function requireUassocCallback(Variable $callback, string $function, int $argNum): void
    {
        $callback = $callback->resolveIndirect();
        if (Variable::TYPE_NULL === $callback->type) {
            throw new \TypeError(self::uassocInvalidCallbackTypeError($function, $argNum));
        }
        if (Variable::TYPE_ARRAY === $callback->type) {
            self::rejectUassocArrayCallback($callback, $function, $argNum);
        }
        if (Variable::TYPE_STRING === $callback->type || VmClosureCall::isClosure($callback)) {
            return;
        }
        if (Variable::TYPE_OBJECT === $callback->type) {
            return;
        }

        throw new \TypeError(self::uassocInvalidCallbackTypeError($function, $argNum));
    }

    private static function uassocInvalidCallbackTypeError(string $function, int $argNum): string
    {
        return $function.'(): Argument #'.$argNum.' must be a valid callback, no array or string given';
    }

    private static function uassocInvalidArrayCallbackTypeError(string $function, int $argNum): string
    {
        return $function.'(): Argument #'.$argNum.' must be a valid callback, array callback must have exactly two members';
    }

    private static function rejectUassocArrayCallback(Variable $callback, string $function, int $argNum): void
    {
        $table = $callback->toArray();
        $idx0 = new Variable(Variable::TYPE_INTEGER);
        $idx0->int(0);
        $idx1 = new Variable(Variable::TYPE_INTEGER);
        $idx1->int(1);
        if (!$table->keyExists($idx0) || !$table->keyExists($idx1)) {
            throw new \TypeError(self::uassocInvalidArrayCallbackTypeError($function, $argNum));
        }

        throw new \TypeError(self::uassocInvalidCallbackTypeError($function, $argNum));
    }

    /**
     * JIT compile-time guard when array_u*() is called with fewer than three args (#12643).
     */
    public static function requireUassocCallbackJitArg(
        \PHPCompiler\JIT\Variable $callback,
        string $function,
        int $argNum
    ): void {
        if (\PHPCompiler\JIT\Variable::TYPE_NULL === $callback->type || $callback->isNullConstant) {
            throw new \TypeError(self::uassocInvalidCallbackTypeError($function, $argNum));
        }
        if (\PHPCompiler\JIT\Variable::TYPE_HASHTABLE === $callback->type
            || ($callback->type & \PHPCompiler\JIT\Variable::IS_NATIVE_ARRAY)) {
            throw new \TypeError(self::uassocInvalidArrayCallbackTypeError($function, $argNum));
        }
        if (\PHPCompiler\JIT\Variable::TYPE_STRING === $callback->type) {
            return;
        }
        if (\PHPCompiler\JIT\Variable::TYPE_OBJECT === $callback->type) {
            return;
        }
        if (null !== $callback->closureCall) {
            return;
        }

        throw new \TypeError(self::uassocInvalidCallbackTypeError($function, $argNum));
    }
}