<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __phpc_class_name_from_id via GetClassJitHelper PHP (#10222).
 *
 * Replaces LLVM select-walk in {@see \PHPCompiler\JIT\ReflectionBuiltinHelper::classNameFromId}.
 * php-src: ext/standard/basic_functions.c — get_class / zend_get_object_classname
 */
final class GetClassRuntime
{
    private const CLASS_NAME_HELPER = 'PHPCompiler\\ext\\standard\\GetClassJitHelper::classNameFromClassId';

    private const ABI_NAME = '__phpc_class_name_from_id';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (null !== self::probeLinked($context)) {
            return;
        }

        self::ensureJitHelperCompiled($context);

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
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
        $context->builder->clearInsertionPosition();
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
}

PHP;
    }

    private static function helperRelativePath(): string
    {
        return '/ext/standard/GetClassJitHelper.php';
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $lc = strtolower(self::CLASS_NAME_HELPER);
        if (isset($context->functions[$lc])) {
            return;
        }

        $map = $context->type->object->allClassNamesById();
        $source = self::helperSourceForMap($map);
        $runtime = $context->runtime;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $source): void {
            $block = $runtime->parseAndCompile($source, 'GetClassJitHelper.php');
            if (null === $block) {
                throw new \LogicException('GetClassJitHelper.php parseAndCompile failed (#10222)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        if (!isset($context->functions[$lc])) {
            throw new \LogicException(self::CLASS_NAME_HELPER.' was not compiled for JIT (#10222)');
        }
    }

    private static function probeLinked(Context $context): ?LlvmFunction
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return $probe;
        }

        return null;
    }
}
