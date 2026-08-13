<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Builtin\StringStripTags;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * strip_tags() for strings (subset of PHP; JIT/AOT via __string__strip_tags).
 *
 * Excess/missing argc → Zend ArgumentCountError (#30592; php-src ext/standard/string.c).
 */
final class strip_tags extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src stub arity: 1..2 — #30592.
        $this->requireArgCountRange($frame, 'strip_tags', 1, 2);
        $argc = \count($frame->calledArgs);
        $subject = self::vmStringArg($frame, 0, 'string');
        $allowed = null;
        if (2 === $argc) {
            $allowed = self::resolveAllowedTagsVm($frame->calledArgs[1]);
        }
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(VmString::stripTags($subject, $allowed))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30592).
        if (!$this->requireArgCountRangeJit($context, $args, 'strip_tags', 1, 2)) {
            return $context->getTypeFromString('__string__*')->constNull();
        }
        $argc = \count($args);
        $allowed = 2 === $argc ? $args[1] : null;

        $subjectArg = $args[0];
        $subjectLiteral = $subjectArg->compileTimeString ?? null;
        // Fold compile-time subject + allowed_tags (string or array). Prior guard only
        // admitted null/array allowed, so AOT strip_tags(..., '<b>') always hit NestedJIT
        // after ensureBridge fixed the insert-block compile error (#21711).
        if (null !== $subjectLiteral) {
            if (null === $allowed) {
                return $context->builder->load(
                    $context->constantStringFromString(VmString::stripTags($subjectLiteral, null))
                );
            }
            $allowedValue = self::resolveAllowedTagsJit($allowed);
            if (null !== $allowedValue) {
                return $context->builder->load(
                    $context->constantStringFromString(VmString::stripTags($subjectLiteral, $allowedValue))
                );
            }
        }

        $subject = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'strip_tags', 0, 'string');
        if (null === $allowed) {
            $allowPtr = $context->builder->load($context->constantStringFromString(''));
        } elseif (self::isAllowedTagsArrayArg($allowed)) {
            $allowPtr = JitStripTags::allowedMarkupFromHashTable(
                $context,
                ArrayBuiltinHelper::loadHashTable($context, $allowed)
            );
        } else {
            $allowPtr = JitStringBuiltinArg::lowerZparamStr($context, $allowed, 'strip_tags', 1, 'allowed_tags');
        }
        StringStripTags::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_strip_tags'),
            $subject,
            $allowPtr
        );
    }

    /** Z_PARAM_STR — Zend 8.4 DEP+coerces null (#21207, ext/standard/string.c). */
    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'strip_tags', $paramName)->toString();
        }

        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'strip_tags',
            $argIndex,
            $paramName
        );
    }

    /**
     * @return string|list<string>|null
     */
    private static function resolveAllowedTagsVm(Variable $allowVar): string|array|null
    {
        $allowVar = $allowVar->resolveIndirect();
        if (Variable::TYPE_NULL === $allowVar->type) {
            return null;
        }
        if (Variable::TYPE_STRING === $allowVar->type) {
            return $allowVar->toString();
        }
        if (Variable::TYPE_ARRAY === $allowVar->type) {
            $tagNames = [];
            foreach ($allowVar->toArray()->iterateKeyed(true) as [, $valueVar]) {
                $tagNames[] = VmString::coerceStringBuiltinArg($valueVar, 'strip_tags', 1, 'allowed_tags');
            }

            return $tagNames;
        }

        throw new \TypeError(self::allowedTagsTypeError($allowVar->type));
    }

    /**
     * @return string|list<string>|null
     */
    private static function resolveAllowedTagsJit(?JITVariable $allowed): string|array|null
    {
        if (null === $allowed) {
            return null;
        }
        // TYPE_VALUE string temps may still carry compileTimeString (#21711).
        if (null !== ($allowed->compileTimeString ?? null)) {
            return $allowed->compileTimeString;
        }
        if (JITVariable::TYPE_STRING === $allowed->type) {
            return null;
        }
        if (!self::isAllowedTagsArrayArg($allowed)) {
            return null;
        }
        $tagNames = self::compileTimeAllowedTagNames($allowed);
        if (null === $tagNames) {
            return null;
        }

        return $tagNames;
    }

    private static function isAllowedTagsArrayArg(JITVariable $arg): bool
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return true;
        }
        if (0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            return true;
        }
        // TYPE_VALUE string temps must not take the hashtable markup path (#21711).
        return JITVariable::TYPE_VALUE === $arg->type && $arg->valueBoxHashtable;
    }

    /**
     * @return list<string>|null
     */
    private static function compileTimeAllowedTagNames(JITVariable $arg): ?array
    {
        if (0 === ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            return null;
        }
        $compileTime = $arg->compileTimeArray ?? null;
        if (!\is_array($compileTime)) {
            return null;
        }
        $names = [];
        foreach ($compileTime as $value) {
            if (!\is_string($value) && !\is_int($value) && !\is_float($value) && !\is_bool($value)) {
                return null;
            }
            $names[] = (string) $value;
        }

        return $names;
    }

    private static function allowedTagsTypeError(int $type): string
    {
        $given = match ($type) {
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_RESOURCE => 'resource',
            default => 'mixed',
        };

        return \sprintf(
            'strip_tags(): Argument #2 ($allowed_tags) must be of type array|string|null, %s given',
            $given
        );
    }
}
