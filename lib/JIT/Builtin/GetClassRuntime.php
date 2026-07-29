<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __phpc_class_name_from_id via GetClassJitHelper PHP (#10222, #24976).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiledFromSource} (per-TU class-id map;
 * peer DefaultTimezone #24962 / HttpBuildQuery #24887 — on-disk helpers use ensureCompiled).
 * Replaces LLVM select-walk in {@see \PHPCompiler\JIT\ReflectionBuiltinHelper::classNameFromId}.
 * php-src: ext/standard/basic_functions.c — get_class / zend_get_object_classname
 */
final class GetClassRuntime
{
    private const CLASS_NAME_HELPER = 'PHPCompiler\\ext\\standard\\GetClassJitHelper::classNameFromClassId';

    private const DEBUG_TYPE_CLASS_NAME_HELPER = 'PHPCompiler\\ext\\standard\\GetClassJitHelper::debugTypeClassNameFromClassId';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::CLASS_NAME_HELPER,
        self::DEBUG_TYPE_CLASS_NAME_HELPER,
    ];

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

        self::ensureJitHelperCompiled($context);

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        if (null === self::probeLinked($context, self::ABI_NAME)) {
            JitVmHelperLink::ensureBridge(
                $context,
                self::ABI_NAME,
                'get_class_name_bridge_entry',
                [$i64],
                $strPtr,
                self::CLASS_NAME_HELPER,
                self::helperRelativePath(),
                [self::CLASS_NAME_HELPER],
                '#10222'
            );
        }
        if (null === self::probeLinked($context, self::DEBUG_TYPE_ABI_NAME)) {
            JitVmHelperLink::ensureBridge(
                $context,
                self::DEBUG_TYPE_ABI_NAME,
                'get_debug_type_class_name_bridge_entry',
                [$i64],
                $strPtr,
                self::DEBUG_TYPE_CLASS_NAME_HELPER,
                self::helperRelativePath(),
                [self::DEBUG_TYPE_CLASS_NAME_HELPER, self::CLASS_NAME_HELPER],
                '#17443'
            );
        }

        if (null !== $savedBlock) {
            BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    public static function helperSourceForMap(array $namesById): string
    {
        $exported = var_export($namesById, true);

        return <<<PHP
<?php

declare(strict_types=1);

namespace PHPCompiler\\ext\\standard;

/** @generated per compile unit — {@see GetClassRuntime} */
final class GetClassJitHelper
{
    /** @var array<int, string> */
    private static array \$namesById = {$exported};

    public static function classNameFromClassId(int \$classId): string
    {
        return self::\$namesById[\$classId] ?? '';
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

    private static function helperRelativePath(): string
    {
        return '/ext/standard/GetClassJitHelper.php';
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $map = $context->type->object->allClassNamesById();
        $source = self::helperSourceForMap($map);
        JitVmHelperLink::ensureCompiledFromSource(
            $context,
            $source,
            'GetClassJitHelper.php',
            self::COMPILED_HELPERS,
            '#24976'
        );
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
