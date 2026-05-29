<?php

declare(strict_types=1);

/**
 * M3 JIT unit probe native compile driver (issues #2332, #2778, #3038).
 * Gate: php bin/compile.php -l test/selfhost/jit_unit_probe/compile_driver.php
 *
 * Compile mode (env dispatch — avoid top-level __DIR__ concat in AOT entry; #1493):
 *   PHP_COMPILER_M3_COMPILE_MODE=compile PHP_COMPILER_M3_RUNTIME_COMPILE=1
 *   PHP_COMPILER_M3_SOURCE=… PHP_COMPILER_M3_OUT=… ./build/selfhost-jit-unit-probe-emit
 */

require_once __DIR__.'/../../../lib/OpCode.php';
require_once __DIR__.'/../../../lib/Block.php';
require_once __DIR__.'/../../../lib/Frame.php';
require_once __DIR__.'/../../../lib/Func.php';
require_once __DIR__.'/../../../lib/Func/PHP.php';
require_once __DIR__.'/../../../lib/VM/ClassProperty.php';
require_once __DIR__.'/../../../lib/VM/ScriptExit.php';
require_once __DIR__.'/../../../lib/JIT/OperandName.php';
require_once __DIR__.'/../../../lib/Printer.php';
require_once __DIR__.'/../../../lib/Runtime.php';
require_once __DIR__.'/../../../lib/NullSafeLivenessDetector.php';
require_once __DIR__.'/../../../lib/Web/ConstStringFolder.php';
require_once __DIR__.'/../../../lib/Web/IncludePathResolver.php';
require_once __DIR__.'/../../../lib/Web/LiteralIncludeDiscovery.php';
require_once __DIR__.'/../../../lib/OpCodeNames.php';
require_once __DIR__.'/../../../lib/VM/Variable.php';
require_once __DIR__.'/../../../lib/Web/DeployRoot.php';
require_once __DIR__.'/../../../lib/Web/SourceBundler.php';
require_once __DIR__.'/../../../lib/Module.php';
require_once __DIR__.'/../../../lib/ModuleAbstract.php';
require_once __DIR__.'/../../../lib/VM/Refcount.php';
require_once __DIR__.'/../../../lib/VM/ErrorReporter.php';
require_once __DIR__.'/../../../lib/VM/ScriptStack.php';
require_once __DIR__.'/../../../lib/VM/HashTable.php';
require_once __DIR__.'/../../../lib/VM/ClassEntry.php';
require_once __DIR__.'/../../../lib/VM/ObjectEntry.php';
require_once __DIR__.'/../../../lib/VM/TypeCheck.php';
require_once __DIR__.'/../../../lib/VM/Optimizer/AssignOp.php';
require_once __DIR__.'/../../../lib/VM/Optimizer.php';
require_once __DIR__.'/../../../lib/VM/Context.php';
require_once __DIR__.'/../../../lib/Handler.php';
require_once __DIR__.'/../../../lib/JIT/Call.php';
require_once __DIR__.'/../../../lib/JIT/Builtin.php';
require_once __DIR__.'/../../../lib/JIT/Result.php';
require_once __DIR__.'/../../../lib/Func/Internal.php';
require_once __DIR__.'/../../../lib/Func/JIT.php';
require_once __DIR__.'/../../../lib/JIT/Variable.php';
require_once __DIR__.'/../../../lib/JIT/IssetHelper.php';
require_once __DIR__.'/../../../lib/Web/Superglobals.php';
require_once __DIR__.'/../../../lib/JIT/Scope.php';
require_once __DIR__.'/../../../lib/JIT/Analyzer.php';
require_once __DIR__.'/../../../lib/JIT/BasicBlockHelper.php';
require_once __DIR__.'/../../../lib/JIT/JitValueBox.php';
require_once __DIR__.'/../../../lib/JIT/SelfHostBuiltinPolicy.php';
require_once __DIR__.'/../../../lib/JIT/CoalesceHelper.php';
require_once __DIR__.'/../../../lib/JIT/IncludeHelper.php';
require_once __DIR__.'/../../../lib/JIT/NullsafeHelper.php';
require_once __DIR__.'/../../../lib/JIT/Progress.php';
require_once __DIR__.'/../../../lib/JIT/Call/Native.php';
require_once __DIR__.'/../../../lib/JIT/Call/ExternalMethod.php';
require_once __DIR__.'/../../../lib/JIT/Call/SplObjectStorageMethod.php';
require_once __DIR__.'/../../../lib/JIT/JitNativeString.php';
require_once __DIR__.'/../../../lib/JIT/JitStringArg.php';
require_once __DIR__.'/../../../lib/JIT/JitLongArg.php';
require_once __DIR__.'/../../../lib/JIT/JitBoolArg.php';
require_once __DIR__.'/../../../lib/JIT/SuperglobalInit.php';
require_once __DIR__.'/../../../lib/JIT/Builtin/MemoryManager.php';
require_once __DIR__.'/../../../lib/JIT/Builtin/MemoryManager/Native.php';
require_once __DIR__.'/../../../lib/JIT/Builtin/MemoryManager/PHP.php';
require_once __DIR__.'/../../../lib/JIT/IteratorHelper.php';
require_once __DIR__.'/../../../lib/JIT/JitStringCompare.php';
require_once __DIR__.'/../../../lib/JIT/JitValueCompare.php';
require_once __DIR__.'/../../../lib/JIT/StringOffsetHelper.php';
require_once __DIR__.'/../../../lib/JIT/ValueEchoHelper.php';
require_once __DIR__.'/../../../lib/JIT/ScriptMagic.php';
require_once __DIR__.'/../../../lib/JIT/Builtin/Refcount.php';
require_once __DIR__.'/../../../lib/JIT/Builtin/Output.php';
require_once __DIR__.'/../../../lib/JIT/Builtin/ErrorHandler.php';
require_once __DIR__.'/../../../lib/JIT/Builtin/ScriptExit.php';
require_once __DIR__.'/../../../lib/JIT/Builtin/IsNullFn.php';
require_once __DIR__.'/../../../lib/JIT/Builtin/PendingHeaders.php';
require_once __DIR__.'/../../../lib/JIT/Builtin/HttpResponseCode.php';
require_once __DIR__.'/../../../lib/JIT/Builtin/StringJsonEncode.php';
require_once __DIR__.'/../../../lib/JIT/Builtin/StringGetenv.php';
require_once __DIR__.'/../../../lib/MethodVisibility.php';
require_once __DIR__.'/../../../lib/JIT/Helper.php';
require_once __DIR__.'/../../../lib/JIT/Context.php';
require_once __DIR__.'/../../../lib/JIT/HashTableHelper.php';
require_once __DIR__.'/../../../lib/JIT.php';
require_once __DIR__.'/../../../lib/VM/OutputBuffer.php';
require_once __DIR__.'/../../../lib/VM.php';
require_once __DIR__.'/../../../lib/Compiler.php';
require_once __DIR__.'/../../../lib/Lint/Issue.php';
require_once __DIR__.'/../../../lib/Lint/UnsupportedRegistry.php';
require_once __DIR__.'/../../../lib/Lint/LintCompiler.php';
require_once __DIR__.'/../../../lib/Lint/Linter.php';
require_once __DIR__.'/../../bootstrap-aot/compiler_smoke.php';
require_once __DIR__.'/../../bootstrap-aot/compile_smoke_m3_emit.php';

if ('compile' === (string) getenv('PHP_COMPILER_M3_COMPILE_MODE')) {
    if (\function_exists('putenv')) {
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        putenv('PHP_COMPILER_M3_COMPILE_DRIVER=1');
    }
    if ('1' !== (string) getenv('PHP_COMPILER_M3_RUNTIME_COMPILE')) {
        echo "jit_unit_probe: emit path blocked (gate: set BOOTSTRAP_M3_RUNTIME_COMPILE=1 and PHP_COMPILER_M3_RUNTIME_COMPILE=1)\n";
        exit(1);
    }
    $sourceFile = getenv('PHP_COMPILER_M3_SOURCE');
    $outFile = getenv('PHP_COMPILER_M3_OUT');
    if (!is_string($sourceFile) || '' === $sourceFile || !is_string($outFile) || '' === $outFile) {
        echo "jit_unit_probe: emit path blocked (set PHP_COMPILER_M3_SOURCE and PHP_COMPILER_M3_OUT for compile mode)\n";
        exit(1);
    }
    exit(\PHPCompiler\BootstrapAot\compile_smoke_m3_emit($sourceFile, $outFile));
}

echo "jit_unit_probe_compile_driver ready\n";
