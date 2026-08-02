<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __phpc_class_name_from_id (#10222, #24976, #26854).
 *
 * Emits a per-TU class-id → name select-walk into the ABI function using the
 * main module's {@see Context::constantStringFromString} (initialized for thin AOT).
 * NestedJIT of GetClassJitHelper left `__string__*` globals null under standalone AOT,
 * so get_class(Error) aborted after a successful id match (#26854).
 *
 * php-src: ext/standard/basic_functions.c — get_class / zend_get_object_classname
 */
final class GetClassRuntime
{
    private const ABI_NAME = '__phpc_class_name_from_id';

    private const DEBUG_TYPE_ABI_NAME = '__phpc_debug_type_class_name_from_id';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureDebugTypeLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        // Mid-function ensureLinked (get_class / UnhandledMatchError "of type …") must not
        // leave the builder cleared — that orphans __phpc_class_name_from_id calls (#24163).
        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::seedThrowableClassNames($context);
        self::emitClassNameAbi($context);
        self::emitDebugTypeAbi($context);

        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * Test helper: shape of a generated per-TU map (switch arms, not a static array).
     *
     * @param array<int, string> $namesById
     */
    public static function helperSourceForMap(array $namesById): string
    {
        $cases = '';
        ksort($namesById, \SORT_NUMERIC);
        foreach ($namesById as $id => $name) {
            $cases .= '            case '.(int) $id.': return '.var_export((string) $name, true).";\n";
        }

        return <<<PHP
<?php

declare(strict_types=1);

namespace PHPCompiler\\ext\\standard;

/** @generated per compile unit — {@see GetClassRuntime} */
final class GetClassJitHelper
{
    public static function classNameFromClassId(int \$classId): string
    {
        switch (\$classId) {
{$cases}            default:
                return '';
        }
    }

    public static function debugTypeClassNameFromClassId(int \$classId): string
    {
        \$name = self::classNameFromClassId(\$classId);
        if (str_contains(\$name, '@anonymous')) {
            return 'class@anonymous';
        }

        return \$name;
    }
}

PHP;
    }

    private static function seedThrowableClassNames(Context $context): void
    {
        $object = $context->type->object;
        foreach (['Throwable', 'Error', 'Exception', 'TypeError', 'ValueError'] as $name) {
            $object->lookup($name);
        }
    }

    private static function emitClassNameAbi(Context $context): void
    {
        if (null !== self::probeLinked($context, self::ABI_NAME)) {
            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $i64);
        $fn = $context->module->addFunction(self::ABI_NAME, $ft);
        $entry = $fn->appendBasicBlock('get_class_name_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $classId = $fn->getParam(0);
        $context->builder->returnValue(self::emitSelectWalk($context, $classId));
        $context->registerFunction(self::ABI_NAME, $fn);
    }

    private static function emitDebugTypeAbi(Context $context): void
    {
        if (null !== self::probeLinked($context, self::DEBUG_TYPE_ABI_NAME)) {
            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $i64);
        $fn = $context->module->addFunction(self::DEBUG_TYPE_ABI_NAME, $ft);
        $entry = $fn->appendBasicBlock('get_debug_type_class_name_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $classId = $fn->getParam(0);
        $anonLabel = $context->builder->load($context->constantStringFromString('class@anonymous'));
        $result = self::emitSelectWalk($context, $classId);
        foreach ($context->type->object->allClassNamesById() as $id => $mapped) {
            if (!str_contains((string) $mapped, '@anonymous')) {
                continue;
            }
            $expected = $context->constantFromInteger((int) $id, 'int64');
            $isId = $context->builder->icmp(Builder::INT_EQ, $classId, $expected);
            $result = $context->builder->select($isId, $anonLabel, $result);
        }
        $context->builder->returnValue($result);
        $context->registerFunction(self::DEBUG_TYPE_ABI_NAME, $fn);
    }

    private static function emitSelectWalk(Context $context, \PHPLLVM\Value $classId): \PHPLLVM\Value
    {
        $result = $context->builder->load($context->constantStringFromString(''));
        foreach ($context->type->object->allClassNamesById() as $id => $name) {
            $expected = $context->constantFromInteger((int) $id, 'int64');
            $isId = $context->builder->icmp(Builder::INT_EQ, $classId, $expected);
            $candidate = $context->builder->load($context->constantStringFromString((string) $name));
            $result = $context->builder->select($isId, $candidate, $result);
        }

        return $result;
    }

    private static function probeLinked(Context $context, string $abiName): ?LlvmFunction
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return $probe;
        }

        return null;
    }
}
