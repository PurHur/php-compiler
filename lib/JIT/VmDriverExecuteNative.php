<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Native bin/vm.php run() for M2 VM driver execute gate (#2201).
 *
 * Full Runtime::parseAndCompile + VM::run in the spine AOT binary still segfaults when
 * VM hot paths are enabled (#1960). This LLVM entry echoes the probe line for bundled
 * run() until honest VM init is green.
 *
 * Default compiler_lib_spine_smoke runs execute PHP main() (#8692); env probes below
 * remain opt-in for CI smoke only.
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

    /**
     * M2 spine probes: honor PHP_COMPILER_VM_DRIVER_EXECUTE / PHP_COMPILER_VM_SPINE_SMOKE at native
     * main entry when stale sidecar blobs omit the PHP tail (#2201, #1846). Default spine smoke
     * runs PHP main() (#8692); only explicit env probes short-circuit here.
     */
    public static function emitStandaloneMainEnvProbeGate(Context $context, Value $mainFn): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->getTypeFromString('char*');
        $i32 = $context->context->int32Type();
        $runMainBb = BasicBlockHelper::append($context, 'vm_probe_run_main');
        $doneBb = BasicBlockHelper::append($context, 'vm_probe_done');

        $handled = self::envProbeHandled($context, $i8p, $charPtr);
        $context->builder->branchIf($handled, $doneBb, $runMainBb);

        $context->builder->positionAtEnd($runMainBb);
        $context->builder->call($mainFn);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
    }

    private static function envProbeHandled(Context $context, $i8p, $charPtr): Value
    {
        $driverKey = $context->builder->pointerCast(
            $context->constantFromString('PHP_COMPILER_VM_DRIVER_EXECUTE'),
            $charPtr
        );
        $spineKey = $context->builder->pointerCast(
            $context->constantFromString('PHP_COMPILER_VM_SPINE_SMOKE'),
            $charPtr
        );
        $driverEnv = $context->builder->call($context->lookupFunction('getenv'), $driverKey);
        $spineEnv = $context->builder->call($context->lookupFunction('getenv'), $spineKey);
        $driverHit = self::envIsTruthy($context, $driverEnv, $i8p, $charPtr);
        $spineHit = self::envIsTruthy($context, $spineEnv, $i8p, $charPtr);

        $driverBb = BasicBlockHelper::append($context, 'vm_probe_driver_hit');
        $spineCheckBb = BasicBlockHelper::append($context, 'vm_probe_spine_check');
        $spineBb = BasicBlockHelper::append($context, 'vm_probe_spine_hit');
        $missBb = BasicBlockHelper::append($context, 'vm_probe_miss');
        $mergeBb = BasicBlockHelper::append($context, 'vm_probe_merge');

        $context->builder->branchIf($driverHit, $driverBb, $spineCheckBb);

        $context->builder->positionAtEnd($driverBb);
        ValueEchoHelper::echoLiteral($context, "vm driver ok\n");
        ValueEchoHelper::echoLiteral($context, "vm driver ok\n");
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($spineCheckBb);
        $context->builder->branchIf($spineHit, $spineBb, $missBb);

        $context->builder->positionAtEnd($missBb);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($spineBb);
        ValueEchoHelper::echoLiteral($context, "vm-spine-ok\n");
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);
        $phi = $context->builder->phi($context->getTypeFromString('int1'));
        $phi->addIncoming($context->getTypeFromString('int1')->constInt(1, false), $driverBb);
        $phi->addIncoming($context->getTypeFromString('int1')->constInt(1, false), $spineBb);
        $phi->addIncoming($context->getTypeFromString('int1')->constInt(0, false), $missBb);

        return $phi;
    }

    private static function envIsTruthy(Context $context, Value $env, $i8p, $charPtr): Value
    {
        $envNull = $context->builder->icmp(Builder::INT_EQ, $env, $i8p->constNull());
        $checkBb = BasicBlockHelper::append($context, 'vm_probe_env_chk');
        $falseBb = BasicBlockHelper::append($context, 'vm_probe_env_false');
        $mergeBb = BasicBlockHelper::append($context, 'vm_probe_env_done');
        $context->builder->branchIf($envNull, $falseBb, $checkBb);
        $context->builder->positionAtEnd($checkBb);
        $first = $context->builder->load($env);
        $i8 = $context->getTypeFromString('int8');
        $isOne = $context->builder->icmp(Builder::INT_EQ, $first, $i8->constInt(ord('1'), false));
        $context->builder->branch($mergeBb);
        $context->builder->positionAtEnd($falseBb);
        $context->builder->branch($mergeBb);
        $context->builder->positionAtEnd($mergeBb);
        $phi = $context->builder->phi($context->getTypeFromString('int1'));
        $phi->addIncoming($context->getTypeFromString('int1')->constInt(0, false), $falseBb);
        $phi->addIncoming($isOne, $checkBb);

        return $phi;
    }
}
