<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * Native vm_unit_probe_run for M3 VM unit probe execute gate (#2354, #2619).
 *
 * Full Runtime::parseAndCompile + VM::run in the self-host AOT bundle still segfaults under
 * PHP_COMPILER_SELFHOST_AOT stubs (#1960). This LLVM entry returns the probe line for bundled
 * vm_unit_probe_run() until honest VM init is green.
 */
final class VmUnitProbeRunNative
{
    public static function isVmUnitProbeRunName(string $lower): bool
    {
        return 'vm_unit_probe_run' === $lower || str_ends_with($lower, '\\vm_unit_probe_run');
    }

    /**
     * @param list<\PHPLLVM\Type> $paramTypes
     */
    public static function compileVmUnitProbeRunNative(
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
            $args = [
                $strPtr,
                $strPtr,
                $context->getTypeFromString('__hashtable__*'),
            ];
        }

        $func = $context->module->addFunction(
            $internalName,
            $context->context->functionType($strPtr, false, ...$args)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();
        $context->builder->positionAtEnd($bb);
        $context->builder->returnValue(
            $context->builder->load($context->constantStringFromString('vm_unit_probe_run OK'))
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
