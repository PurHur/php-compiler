<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VMVariable;

/**
 * Supported vs deferred preg_replace_callback() callback forms (issue #1177, #4442, #36382).
 *
 * VM accepts any callable. JIT/AOT lowers compile-time string user-function names and
 * compile-time static array callables `['Class','method']` / `[__CLASS__,'method']`
 * ([#142](https://github.com/PurHur/php-compiler/issues/142), Nyholm Uri.php via #36382).
 */
final class PregReplaceCallbackPolicy
{
    public const DEFERRED_SUMMARY =
        'preg_replace_callback callbacks: compile-time string user-function names and '
        .'static [Class, method] array callables for JIT/AOT; closures/invokables VM-only';

    public const DEFERRED_KINDS = 'closures, bound [object, method] array callables, and invokable objects';

    public const JIT_SUBSET =
        'compile-time string user-function names or static [Class, method] array callables '
        .'in this compile unit';

    public static function isJitLowerable(JITVariable $callback): bool
    {
        if (self::isJitLowerableScalar(
            $callback->type,
            $callback->isNullConstant,
            $callback->compileTimeString
        )) {
            return true;
        }

        return null !== self::compileTimeStaticArrayCallableNames($callback);
    }

    public static function isJitLowerableScalar(int $type, bool $isNullConstant, ?string $compileTimeString): bool
    {
        return JITVariable::TYPE_STRING === $type && null !== $compileTimeString;
    }

    /**
     * Packed `['Class','method']` literal tracked on {@see JITVariable::$compileTimeArray}.
     *
     * @return array{0:string,1:string}|null
     */
    public static function compileTimeStaticArrayCallableNames(JITVariable $callback): ?array
    {
        $arr = $callback->compileTimeArray;
        if (!\is_array($arr) || !isset($arr[0], $arr[1])) {
            return null;
        }
        if (!\is_string($arr[0]) || !\is_string($arr[1])) {
            return null;
        }
        if ('' === $arr[0] || '' === $arr[1]) {
            return null;
        }

        return [$arr[0], $arr[1]];
    }

    public static function isVmSupportedType(int $type): bool
    {
        return \in_array($type, [
            VMVariable::TYPE_STRING,
            VMVariable::TYPE_ARRAY,
            VMVariable::TYPE_OBJECT,
        ], true);
    }

    public static function jitRejectionMessage(): string
    {
        return 'preg_replace_callback() callback must be '.self::JIT_SUBSET
            .' for JIT/AOT in this compiler build; '.self::DEFERRED_KINDS.' are deferred (#1177, #142, #36382)';
    }

    public static function vmRejectionMessage(): string
    {
        return 'preg_replace_callback(): Argument #2 ($callback) must be a valid callback, no array or string given';
    }
}
