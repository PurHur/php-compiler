<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Block;
use PHPCompiler\Frame;
use PHPCompiler\OpCode;

/**
 * Zend-shaped ArgumentCountError messages for user function/method calls (issue #10176).
 *
 * php-src: Zend/zend_execute.c — too few arguments guard
 */
final class ParamArgumentCountError
{
    public static function forTooFewAtReceive(Frame $frame, int $missingParamIndex): \ArgumentCountError
    {
        $block = $frame->block;
        $paramCount = \count($block->paramNames);
        $minRequired = self::countMinimumRequired($block);
        // Trailing optionals / variadics only — optional-before-required counts as required (#25728).
        $hasTrailingOptional = $minRequired < $paramCount;
        $passed = self::countPassedUserArgs($frame);
        $expectedPhrase = $hasTrailingOptional
            ? \sprintf('at least %d expected', $minRequired)
            : \sprintf('exactly %d expected', $minRequired);
        [$scriptPath, $callSiteLine] = self::callSite($frame);
        $function = self::formatUserFunctionName(self::resolveFunctionName($frame));

        return new \ArgumentCountError(\sprintf(
            'Too few arguments to function %s(), %d passed in %s on line %d and %s',
            $function,
            $passed,
            $scriptPath,
            $callSiteLine,
            $expectedPhrase
        ));
    }

    /**
     * Named-arg omission of an effectively-required parameter (zend_execute.c, #25728).
     *
     * Message shape: {@code f(): Argument #1 ($a) not passed}
     */
    public static function forNamedArgNotPassed(Frame $frame, int $paramIndex): \ArgumentCountError
    {
        $name = $frame->block->paramNames[$paramIndex] ?? '';
        $function = self::formatUserFunctionName(self::resolveFunctionName($frame));

        return new \ArgumentCountError(\sprintf(
            '%s(): Argument #%d ($%s) not passed',
            $function,
            $paramIndex + 1,
            $name
        ));
    }

    /**
     * True when a later call argument was supplied (named-arg hole before a filled slot).
     *
     * @param array<int, mixed> $calledArgs
     */
    public static function calledArgsHaveIndexAbove(array $calledArgs, int $recvIdx): bool
    {
        foreach ($calledArgs as $idx => $_) {
            if ((int) $idx > $recvIdx) {
                return true;
            }
        }

        return false;
    }

    private static function countPassedUserArgs(Frame $frame): int
    {
        $passed = \count($frame->calledArgs);
        if (
            null !== $frame->block->func
            && null !== $frame->block->func->class
            && !(($frame->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC)
            && \array_key_exists(0, $frame->calledArgs)
        ) {
            $passed = max(0, $passed - 1);
        }

        return $passed;
    }

    /** @return array{0: string, 1: int} */
    private static function callSite(Frame $frame): array
    {
        $caller = $frame->parent ?? $frame;
        $scriptPath = '' !== $caller->scriptPath
            ? $caller->scriptPath
            : ExceptionSupport::throwSiteFile($caller);
        $callSiteLine = $caller->callSiteLine;
        if ($callSiteLine <= 0) {
            for ($f = $caller; null !== $f; $f = $f->parent) {
                if ($f->callSiteLine > 0) {
                    $callSiteLine = $f->callSiteLine;
                    break;
                }
            }
        }
        // Same recovery as {@see UserParamErrorContext} for property-hook invoke (#29666).
        if ($callSiteLine <= 0) {
            $callSiteLine = FatalSite::lineFromOpcodes($caller);
        }
        if ($callSiteLine <= 0) {
            $callSiteLine = 1;
        }

        return [$scriptPath, $callSiteLine];
    }

    /**
     * Zend TypeError / ArgumentCountError callable label for a user call frame (#19526, #29953).
     *
     * Method-scoped closures/arrows → {@code Class::{closure}}; free closures → {@code {closure}}.
     */
    public static function resolveFunctionName(Frame $frame): string
    {
        // Callee ARG_RECV frames leave $frame->call null; the caller still holds Func\PHP (#19526).
        $call = $frame->call;
        if (!($call instanceof \PHPCompiler\Func\PHP) && null !== $frame->parent) {
            $call = $frame->parent->call;
        }

        // Fake closures (fromCallable / getClosure) keep the underlying method display name.
        $state = self::closureStateFromFrame($frame);
        if (null !== $state && null !== $state->methodName && '' !== $state->methodName) {
            $scope = $state->boundScopeClass ?? '';
            if ('' !== $scope) {
                return \PHPCompiler\SourcePreprocessor\PropertyHooks::zendTypeErrorCallableName(
                    $scope.'::'.$state->methodName
                );
            }

            return \PHPCompiler\SourcePreprocessor\PropertyHooks::zendTypeErrorCallableName($state->methodName);
        }

        if ($call instanceof \PHPCompiler\Func\PHP) {
            return self::qualifyClosureDisplayName(
                \PHPCompiler\SourcePreprocessor\PropertyHooks::zendTypeErrorCallableName($call->getName()),
                self::closureScopeClass($frame, $call)
            );
        }
        $method = null;
        $cfgFunc = $frame->block->func;
        if (null !== $cfgFunc && \is_string($cfgFunc->name)) {
            $method = $cfgFunc->name;
        }
        // Only instance methods use arg0 as $this — free functions may take an object (#19526).
        $isInstanceMethod = null !== $cfgFunc
            && null !== $cfgFunc->class
            && !(($cfgFunc->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC);
        if (null !== $method && $isInstanceMethod && !self::isBareClosureLabel($method)) {
            $selfVar = null;
            if ([] !== $frame->callArgs) {
                $selfVar = $frame->callArgs[0]->resolveIndirect();
            } elseif (\array_key_exists(0, $frame->calledArgs)) {
                $selfVar = $frame->calledArgs[0]->resolveIndirect();
            }
            if (null !== $selfVar && Variable::TYPE_OBJECT === $selfVar->type) {
                return \PHPCompiler\SourcePreprocessor\PropertyHooks::zendTypeErrorCallableName(
                    $selfVar->toObject()->class->name.'::'.$method
                );
            }
        }
        if (null !== $method) {
            return self::qualifyClosureDisplayName(
                \PHPCompiler\SourcePreprocessor\PropertyHooks::zendTypeErrorCallableName($method),
                self::closureScopeClass($frame, null)
            );
        }

        return '{closure}';
    }

    /**
     * Zend TypeError callable label for a CFG func (return checks / JIT Native framing) (#29953).
     */
    public static function typeErrorDisplayNameForCfgFunc(?\PHPCfg\Func $func, ?string $fallbackName = null): string
    {
        $name = $fallbackName ?? '';
        if (null !== $func && \is_string($func->name) && '' !== $func->name) {
            $name = $func->name;
        }
        if ('' === $name) {
            return '{closure}';
        }
        if ('{main}' === $name) {
            return '{main}';
        }
        $name = \PHPCompiler\SourcePreprocessor\PropertyHooks::zendTypeErrorCallableName($name);
        $scope = null;
        if (null !== $func && null !== $func->class) {
            $className = $func->class->value ?? null;
            if (\is_string($className) && '' !== $className) {
                $scope = $className;
            }
        }
        if (null !== $scope && '' !== $scope) {
            if (self::isBareClosureLabel($name)) {
                return self::formatUserFunctionName($scope.'::'.$name);
            }
            // Named methods keep Class::method (ClassReturnCheck / ScalarReturnCheck).
            if (!str_contains($name, '::')) {
                return \PHPCompiler\SourcePreprocessor\PropertyHooks::zendTypeErrorCallableName(
                    $scope.'::'.$name
                );
            }
        }

        return self::formatUserFunctionName($name);
    }

    public static function formatUserFunctionName(string $name): string
    {
        // php-cfg `{anonymous}#N` / `{closure}_N` → Zend `{closure}`; with class scope →
        // `Class::{closure}` (#29095, #29025, #29953).
        if (preg_match('/^(?:(.*)::)?(\{anonymous\}(?:#\d+)?|\{closure\}(?:_\d*)?)$/', $name, $m)) {
            $prefix = isset($m[1]) && '' !== $m[1] ? $m[1].'::' : '';

            return $prefix.'{closure}';
        }
        if (!str_contains($name, '@anonymous')) {
            return $name;
        }

        return preg_replace('/(@anonymous)\0[^\0]+?(?=::|$)/', '$1', $name) ?? $name;
    }

    public static function isBareClosureLabel(string $name): bool
    {
        return str_starts_with($name, '{anonymous}') || str_starts_with($name, '{closure}');
    }

    private static function qualifyClosureDisplayName(string $name, ?string $scope): string
    {
        if (null === $scope || '' === $scope || !self::isBareClosureLabel($name)) {
            return $name;
        }

        return $scope.'::'.$name;
    }

    private static function closureStateFromFrame(Frame $frame): ?ClosureState
    {
        foreach ([$frame, $frame->parent] as $f) {
            if (null === $f) {
                continue;
            }
            if (null !== $f->closureCall) {
                return $f->closureCall;
            }
            if (null !== $f->pendingClosureInvoke) {
                return $f->pendingClosureInvoke;
            }
        }

        return null;
    }

    private static function closureScopeClass(Frame $frame, ?\PHPCompiler\Func\PHP $call): ?string
    {
        $state = self::closureStateFromFrame($frame);
        if (null !== $state && null !== $state->boundScopeClass && '' !== $state->boundScopeClass) {
            return $state->boundScopeClass;
        }
        $func = null;
        if (null !== $call) {
            $func = $call->block->func ?? null;
        }
        if (null === $func) {
            $func = $frame->block->func ?? null;
        }
        if (null !== $func && null !== $func->class) {
            $className = $func->class->value ?? null;
            if (\is_string($className) && '' !== $className) {
                return $className;
            }
        }

        return null;
    }

    /**
     * Count of parameters that must be passed (zend_execute / Reflection required count).
     *
     * Trailing defaults and a trailing variadic are optional; a default followed by a
     * later required (non-default, non-variadic) parameter remains required (#25728).
     */
    public static function countMinimumRequired(Block $block): int
    {
        $paramCount = \count($block->paramNames);
        $required = $paramCount;
        for ($i = $paramCount - 1; $i >= 0; --$i) {
            if ($block->variadicParamIndex === $i) {
                $required = $i;
                continue;
            }
            if (self::parameterHasDefault($block, $i)) {
                $required = $i;
                continue;
            }
            break;
        }

        return $required;
    }

    /**
     * Parameter must be passed: no default, or default before a later required param (#25728).
     */
    public static function parameterIsEffectivelyRequired(Block $block, int $paramIndex): bool
    {
        if ($block->variadicParamIndex === $paramIndex) {
            return false;
        }
        if (!self::parameterHasDefault($block, $paramIndex)) {
            return true;
        }
        $paramCount = \count($block->paramNames);
        for ($i = $paramIndex + 1; $i < $paramCount; ++$i) {
            if ($block->variadicParamIndex === $i) {
                return false;
            }
            if (!self::parameterHasDefault($block, $i)) {
                return true;
            }
        }

        return false;
    }

    public static function parameterHasDefault(Block $block, int $paramIndex): bool
    {
        if (isset($block->paramRuntimeDefaultInitBlocks[$paramIndex])) {
            return true;
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV !== $op->type || (int) $op->arg2 !== $paramIndex) {
                continue;
            }

            return null !== $op->arg3;
        }

        return false;
    }
}
