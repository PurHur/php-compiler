<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Lint\UnsupportedFeature;
use PHPCompiler\Lint\UnsupportedRegistry;
use PHPCompiler\VM\Variable as VMVariable;

/**
 * Supported vs deferred set_error_handler() callback forms (issue #1379 / #36382).
 *
 * JIT/AOT: compile-time string user-function names and closures/arrows (incl. static
 * + use()-by-ref — Nyholm Stream::getContents). Array callables / invokables stay deferred.
 */
final class ErrorHandlerCallbackPolicy
{
    public const DEFERRED_SUMMARY =
        'set_error_handler callbacks: string user-function names + closures for JIT/AOT; array/invokable deferred';

    public const DEFERRED_KINDS = 'array callables and invokable objects';

    public static function isVmSupportedType(int $type): bool
    {
        return VMVariable::TYPE_STRING === $type || VMVariable::TYPE_NULL === $type;
    }

    public static function vmRejectionMessage(): string
    {
        $row = UnsupportedRegistry::feature('set-error-handler-callback');

        return UnsupportedFeature::format(
            $row['feature'],
            $row['matrixRow'],
            $row['issue'],
            $row['alternative']
        );
    }

    public static function isJitLowerable(Variable $callback): bool
    {
        if (null !== $callback->closureCall) {
            return true;
        }

        return self::isJitLowerableScalar(
            $callback->type,
            $callback->isNullConstant,
            $callback->compileTimeString
        );
    }

    public static function isJitLowerableScalar(int $type, bool $isNullConstant, ?string $compileTimeString): bool
    {
        if ($isNullConstant) {
            return false;
        }

        return Variable::TYPE_STRING === $type && null !== $compileTimeString;
    }

    public static function jitRejectionMessage(): string
    {
        $row = UnsupportedRegistry::feature('set-error-handler-callback');

        return UnsupportedFeature::format(
            $row['feature'],
            $row['matrixRow'],
            $row['issue'],
            $row['alternative']
        );
    }

    /**
     * Zend set_error_handler() invalid callback TypeError (#6234, ext/standard/basic_functions.c).
     */
    public static function invalidCallbackTypeError(): string
    {
        return 'set_error_handler(): Argument #1 ($callback) must be a valid callback or null, no array or string given';
    }
}
