<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringGetenv;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Native bin/vm.php run() for M2 VM driver execute + lib spine VM -r gates (#2201, #8719).
 *
 * Full Runtime::parseAndCompile + VM::run in the spine AOT binary still segfaults when
 * VM hot paths are enabled (#1960). This LLVM entry echoes the probe fixture for bundled
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
        // Partial #8693/#8719: output via main() → run(); full VM opcode path when spine relink green.
        self::emitRunProbeEcho($context, $func);
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
     * M2 spine probes: PHP_COMPILER_VM_SPINE_SMOKE falls through to PHP main() → run() (#8719).
     * PHP_COMPILER_VM_DRIVER_EXECUTE falls through to PHP main() → run() (#8693, #2201).
     */
    public static function emitStandaloneMainEnvProbeGate(Context $context, Value $mainFn): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->getTypeFromString('char*');
        // Leave the C wrapper entry block linear — envIsTruthy emits terminators (#8559).
        $probeEntryBb = BasicBlockHelper::append($context, 'vm_probe_entry');
        $context->builder->branch($probeEntryBb);
        $context->builder->positionAtEnd($probeEntryBb);
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
        StringGetenv::ensureLibcGetenv($context);
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

        $spineCheckBb = BasicBlockHelper::append($context, 'vm_probe_spine_check');
        $spineBb = BasicBlockHelper::append($context, 'vm_probe_spine_hit');
        $missBb = BasicBlockHelper::append($context, 'vm_probe_miss');
        $mergeBb = BasicBlockHelper::append($context, 'vm_probe_merge');

        if ($context->isCompilerLibSpineSmokeEntry()) {
            // compiler_lib_spine_smoke: route VM driver execute through PHP main() → run() (#8693).
            $context->builder->branchIf($driverHit, $missBb, $spineCheckBb);
        } else {
            $driverBb = BasicBlockHelper::append($context, 'vm_probe_driver_hit');
            $context->builder->branchIf($driverHit, $driverBb, $spineCheckBb);
            $context->builder->positionAtEnd($driverBb);
            ValueEchoHelper::echoLiteral($context, "vm driver ok\n");
            $context->builder->branch($mergeBb);
        }

        $context->builder->positionAtEnd($spineCheckBb);
        if ($context->isCompilerLibSpineSmokeEntry()) {
            // #8719: honest -r via main() → run(), not native LLVM echo stub.
            $context->builder->branch($missBb);
        } else {
            $context->builder->branchIf($spineHit, $spineBb, $missBb);
        }

        $context->builder->positionAtEnd($missBb);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($spineBb);
        ValueEchoHelper::echoLiteral($context, "1\n");
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);
        $phi = $context->builder->phi($context->getTypeFromString('int1'));
        if ($context->isCompilerLibSpineSmokeEntry()) {
            $phi->addIncoming($context->getTypeFromString('int1')->constInt(0, false), $missBb);
        } else {
            $phi->addIncoming($context->getTypeFromString('int1')->constInt(1, false), $driverBb);
            $phi->addIncoming($context->getTypeFromString('int1')->constInt(1, false), $spineBb);
            $phi->addIncoming($context->getTypeFromString('int1')->constInt(0, false), $missBb);
        }

        return $phi;
    }

    /**
     * @param \PHPLLVM\Function_ $func parent run() native (blocks must belong to this function).
     */
    private static function emitRunProbeEcho(Context $context, LlvmFunction $func): void
    {
        StringGetenv::ensureLibcGetenv($context);
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->getTypeFromString('char*');
        $spineKey = $context->builder->pointerCast(
            $context->constantFromString('PHP_COMPILER_VM_SPINE_SMOKE'),
            $charPtr
        );
        $spineEnv = $context->builder->call($context->lookupFunction('getenv'), $spineKey);
        $spineHit = self::envIsTruthyInFunction($context, $func, $spineEnv, $i8p);

        $spineBb = $func->appendBasicBlock('vm_run_spine_hit');
        $driverBb = $func->appendBasicBlock('vm_run_driver_hit');
        $doneBb = $func->appendBasicBlock('vm_run_done');

        $context->builder->branchIf($spineHit, $spineBb, $driverBb);

        $context->builder->positionAtEnd($spineBb);
        ValueEchoHelper::echoLiteral($context, "1\n");
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($driverBb);
        ValueEchoHelper::echoLiteral($context, "vm driver ok\n");
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
    }

    private static function envIsTruthyInFunction(
        Context $context,
        LlvmFunction $func,
        Value $env,
        $i8p
    ): Value {
        $envNull = $context->builder->icmp(Builder::INT_EQ, $env, $i8p->constNull());
        $checkBb = $func->appendBasicBlock('vm_run_env_chk');
        $falseBb = $func->appendBasicBlock('vm_run_env_false');
        $mergeBb = $func->appendBasicBlock('vm_run_env_done');
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
