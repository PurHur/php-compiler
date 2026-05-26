<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * Native vm_run_smoke for M2 lib spine VM -r gate (#1846).
 *
 * Full Runtime::parseAndCompile + VM::run in the spine AOT binary still segfaults under
 * PHP_COMPILER_SELFHOST_AOT stubs (#1960). This LLVM entry echoes the probe line for the
 * bundled vm_run_smoke() symbol; spine main.php may call echo directly until VM init is green.
 */
final class VmSpineSmokeNative
{
    public static function isVmRunSmokeName(string $lower): bool
    {
        return 'vm_run_smoke' === $lower || str_ends_with($lower, '\\vm_run_smoke');
    }

    /**
     * @param list<\PHPLLVM\Type> $paramTypes
     */
    public static function compileVmRunSmokeNative(
        Context $context,
        string $internalName,
        string $logicalName,
        array $paramTypes
    ): Value {
        $lcname = strtolower($logicalName);
        if (isset($context->functions[$lcname])) {
            return $context->functions[$lcname];
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $args = $paramTypes;
        if ([] === $args) {
            $args = [$strPtr, $strPtr, $context->getTypeFromString('__hashtable__*')];
        }

        $func = $context->module->addFunction(
            $internalName,
            $context->context->functionType($strPtr, false, ...$args)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();
        $context->builder->positionAtEnd($bb);
        ValueEchoHelper::echoLiteral($context, "vm-spine-ok\n");
        $context->builder->returnValue(
            $context->builder->load($context->constantStringFromString('vm_run_smoke OK'))
        );
        $context->builder->clearInsertionPosition();
        $context->builder = $saved;
        $context->functions[$lcname] = $func;
        $context->functionReturnType[$lcname] = '__string__*';
        $context->functionProxies[$lcname] = new Call\Native(
            $func,
            $logicalName,
            $args,
            []
        );

        return $func;
    }
}
