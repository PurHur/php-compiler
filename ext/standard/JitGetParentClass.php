<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT\Builtin\StringGetParentClass;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT helper for get_parent_class() via GetParentClassJitHelper PHP (#3483, php-in-PHP).
 *
 * Compile-time literal class names keep registry fast path; runtime operands route through PHP.
 * php-src: ext/standard/class.c — PHP_FUNCTION(get_parent_class)
 */
final class JitGetParentClass
{
    private const OBJECT_OR_VALID_CLASS_NAME_TYPE_ERROR =
        'get_parent_class(): Argument #1 ($object_or_class) must be an object or a valid class name, %s given';

    public static function invoke(Context $context, JITVariable $whatArg): Value
    {
        $literal = JitStringArg::compileTimeLiteral($whatArg);
        if (null !== $literal) {
            return self::invokeForClassName($context, $literal);
        }

        return self::routeThroughPhpHelper($context, $whatArg);
    }

    /**
     * Zero-arg get_parent_class() — defining-scope parent or false (#26369).
     *
     * php-src: Zend/zend_builtin_functions.c — zend_get_executed_scope() then ce->parent.
     */
    public static function invokeNoArg(Context $context): Value
    {
        if (CompilerVersion::supportsGetClassParentClassParameterlessDeprecation()) {
            VmEngineBuiltinDeprecation::emitJitCallingWithoutArguments($context, 'get_parent_class');
        }
        $block = $context->jitEnclosingBlock;
        if (!$block instanceof Block || null === $block->func || null === $block->func->class) {
            return self::returnFalse($context);
        }

        return self::invokeForClassName($context, $block->func->class->value);
    }

    private static function routeThroughPhpHelper(Context $context, JITVariable $whatArg): Value
    {
        return StringGetParentClass::invoke($context, self::operandToValueBox($context, $whatArg));
    }

    private static function operandToValueBox(Context $context, JITVariable $whatArg): Value
    {
        if (JITVariable::TYPE_VALUE === $whatArg->type) {
            return JitValueBox::valuePtrFromVariable($context, $whatArg);
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        if (JITVariable::TYPE_OBJECT === $whatArg->type) {
            $context->builder->call(
                $context->lookupFunction('__value__writeObject'),
                $ptr,
                $context->helper->loadValue($whatArg)
            );
        } elseif (JITVariable::TYPE_STRING === $whatArg->type) {
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $ptr,
                $context->helper->loadValue($whatArg)
            );
        } else {
            throw new \LogicException(
                'get_parent_class() operand must be a compile-time literal, object, or value box in this compiler build'
            );
        }

        return $ptr;
    }

    private static function invokeForClassName(Context $context, string $className): Value
    {
        if (!self::classExists($context, $className)) {
            self::emitJitTypeErrorAndAbort($context, self::OBJECT_OR_VALID_CLASS_NAME_TYPE_ERROR, 'string');

            return self::returnFalse($context);
        }
        $parentName = self::resolveParentDisplayName($context, $className);
        if (null === $parentName) {
            return self::returnFalse($context);
        }

        return self::returnString($context, $parentName);
    }

    private static function emitJitTypeErrorAndAbort(Context $context, string $template, string $given): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, \sprintf($template, $given));
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function classExists(Context $context, string $className): bool
    {
        $lc = strtolower(ltrim($className, '\\'));
        $object = $context->type->object;
        if ($object->hasUserDeclaredClass($className)) {
            return true;
        }
        $vm = $context->runtime->vmContext;

        return null !== $vm && isset($vm->classes[$lc]);
    }

    private static function resolveParentDisplayName(Context $context, string $className): ?string
    {
        $lc = strtolower(ltrim($className, '\\'));
        $object = $context->type->object;
        if ($object->hasUserDeclaredClass($className)) {
            return $object->parentClassDisplayName($className);
        }

        $vm = $context->runtime->vmContext;
        if (null === $vm || !isset($vm->classes[$lc])) {
            return null;
        }
        $entry = $vm->classes[$lc];
        if ($entry->isInterface || $entry->isTrait || $entry->isEnum) {
            return null;
        }

        return VmReflection::parentClassName($entry, $vm);
    }

    private static function returnString(Context $context, string $value): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $context->builder->load($context->constantStringFromString($value))
        );

        return $ptr;
    }

    private static function returnFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );

        return $ptr;
    }
}
