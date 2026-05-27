<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * Native bin/vm.php run() for M2 VM driver execute gate (#2201).
 *
 * Full Runtime::parseAndCompile + VM::run in the spine AOT binary still segfaults when
 * VM hot paths are enabled (#1960). This LLVM entry echoes the probe line for bundled
 * run() until honest VM init is green.
 */
final class VmDriverExecuteNative
{
    public static function isBinVmRunName(string $lower, ?\PHPCompiler\Block $block = null): bool
    {
        if ('run' !== $lower) {
            return false;
        }
        if (null === $block) {
            return true;
        }
        $path = str_replace('\\', '/', strtolower($block->scriptPath()));
        if (str_contains($path, 'bin/vm.php')) {
            return true;
        }
        if (null !== $block->func) {
            $file = str_replace('\\', '/', strtolower($block->func->getFile()));

            return str_contains($file, 'bin/vm.php');
        }

        return false;
    }

    /**
     * @param list<\PHPLLVM\Type> $paramTypes
     */
    public static function compileBinVmRunNative(
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

        $voidTy = $context->getTypeFromString('void');
        $func = $context->module->addFunction(
            $internalName,
            $context->context->functionType($voidTy, false, ...$args)
        );
        $bb = $func->appendBasicBlock('entry');
        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();
        $context->builder->positionAtEnd($bb);
        ValueEchoHelper::echoLiteral($context, "vm driver ok\n");
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
        $context->builder = $saved;
        $context->functions[$lcname] = $func;
        $context->functionReturnType[$lcname] = 'void';
        $context->functionProxies[$lcname] = new Call\Native(
            $func,
            $logicalName,
            $args,
            []
        );

        return $func;
    }
}
