<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Bundled Compiler.php self-host AOT lint (issues #212, #78).
 *
 * JIT compile-time parameter defaults covered for the minimal bundle (#556):
 * null, int, float, bool, string, and array (including empty []).
 *
 * @group aot-lint
 */
final class CompilerSelfhostLintTest extends TestCase
{
    private const BUNDLE_ENTRY = 'test/selfhost/compiler_minimal/main.php';

    public function testBundledCompilerMinimalLintExitZero(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/'.self::BUNDLE_ENTRY;
        $this->assertFileExists($target);

        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($bin)
            .' -l '.escapeshellarg($target).' 2>&1';
        exec($cmd, $lines, $exit);
        $this->assertSame(
            0,
            $exit,
            implode("\n", $lines)."\n".'compile.php -l failed for '.self::BUNDLE_ENTRY
        );
    }

    public function testLiteralIncludeDiscoveryFindsCompilerClosure(): void
    {
        $root = dirname(__DIR__, 2);
        $entry = $root.'/'.self::BUNDLE_ENTRY;
        $runtime = new Runtime(Runtime::MODE_AOT);
        $paths = Web\LiteralIncludeDiscovery::discoverAbsolutePaths($runtime, $entry);
        $rels = array_map(
            static fn (string $abs): string => substr($abs, strlen($root) + 1),
            $paths
        );
        sort($rels, SORT_STRING);
        $expected = [
            'lib/Block.php',
            'lib/Compiler.php',
            'lib/Frame.php',
            'lib/Func.php',
            'lib/Func/Internal.php',
            'lib/Func/JIT.php',
            'lib/Func/PHP.php',
            'lib/Handler.php',
            'lib/JIT.php',
            'lib/JIT/Analyzer.php',
            'lib/JIT/BasicBlockHelper.php',
            'lib/JIT/Builtin.php',
            'lib/JIT/Builtin/ErrorHandler.php',
            'lib/JIT/Builtin/HttpResponseCode.php',
            'lib/JIT/Builtin/Internal.php',
            'lib/JIT/Builtin/IsNullFn.php',
            'lib/JIT/Builtin/MemoryManager.php',
            'lib/JIT/Builtin/MemoryManager/Native.php',
            'lib/JIT/Builtin/MemoryManager/PHP.php',
            'lib/JIT/Builtin/Output.php',
            'lib/JIT/Builtin/PendingHeaders.php',
            'lib/JIT/Builtin/Refcount.php',
            'lib/JIT/Builtin/ScriptExit.php',
            'lib/JIT/Builtin/StringAddslashes.php',
            'lib/JIT/Builtin/StringDateTime.php',
            'lib/JIT/Builtin/StringDeployPath.php',
            'lib/JIT/Builtin/StringFileGetContents.php',
            'lib/JIT/Builtin/StringFilePutContents.php',
            'lib/JIT/Builtin/StringGetenv.php',
            'lib/JIT/Builtin/StringHtmlspecialchars.php',
            'lib/JIT/Builtin/StringJsonEncode.php',
            'lib/JIT/Builtin/StringNl2br.php',
            'lib/JIT/Builtin/StringPregQuote.php',
            'lib/JIT/Builtin/StringQuotemeta.php',
            'lib/JIT/Builtin/StringRandomBytes.php',
            'lib/JIT/Builtin/StringReadfile.php',
            'lib/JIT/Builtin/StringStripslashes.php',
            'lib/JIT/Builtin/StringUcwords.php',
            'lib/JIT/Builtin/StringUrlencode.php',
            'lib/JIT/Builtin/Type/MaskedArray.php',
            'lib/JIT/Builtin/Type/NativeArray.php',
            'lib/JIT/Builtin/Type/Value.php',
            'lib/JIT/Builtin/VarArg.php',
            'lib/JIT/Call.php',
            'lib/JIT/Call/ExternalMethod.php',
            'lib/JIT/Call/Native.php',
            'lib/JIT/Call/SplObjectStorageMethod.php',
            'lib/JIT/Call/Vararg.php',
            'lib/JIT/Builtin/CoalesceRuntime.php',
            'lib/JIT/CoalesceHelper.php',
            'lib/JIT/Context.php',
            'lib/JIT/HashTableHelper.php',
            'lib/JIT/Helper.php',
            'lib/JIT/IncludeHelper.php',
            'lib/JIT/IssetHelper.php',
            'lib/JIT/IteratorHelper.php',
            'lib/JIT/JitBoolArg.php',
            'lib/JIT/JitLongArg.php',
            'lib/JIT/JitNativeString.php',
            'lib/JIT/JitStringArg.php',
            'lib/JIT/JitStringCompare.php',
            'lib/JIT/JitValueBox.php',
            'lib/JIT/JitValueCompare.php',
            'lib/JIT/NullsafeHelper.php',
            'lib/JIT/OperandName.php',
            'lib/JIT/Progress.php',
            'lib/JIT/Result.php',
            'lib/JIT/Scope.php',
            'lib/JIT/ScopeBuiltinHelper.php',
            'lib/JIT/ScriptMagic.php',
            'lib/JIT/SelfHostBuiltinPolicy.php',
            'lib/JIT/StringOffsetHelper.php',
            'lib/JIT/SuperglobalInit.php',
            'lib/JIT/ValueEchoHelper.php',
            'lib/JIT/Variable.php',
            'lib/Lint/Issue.php',
            'lib/Lint/LintCompiler.php',
            'lib/Lint/Linter.php',
            'lib/Lint/UnsupportedRegistry.php',
            'lib/MethodVisibility.php',
            'lib/Module.php',
            'lib/ModuleAbstract.php',
            'lib/NullSafeLivenessDetector.php',
            'lib/OpCode.php',
            'lib/OpCodeNames.php',
            'lib/Printer.php',
            'lib/Runtime.php',
            'lib/VM.php',
            'lib/VM/ClassEntry.php',
            'lib/VM/ClassProperty.php',
            'lib/VM/Context.php',
            'lib/VM/ErrorReporter.php',
            'lib/VM/HashTable.php',
            'lib/VM/ObjectEntry.php',
            'lib/VM/Optimizer.php',
            'lib/VM/Optimizer/AssignOp.php',
            'lib/VM/OutputBuffer.php',
            'lib/VM/Refcount.php',
            'lib/VM/ScriptExit.php',
            'lib/VM/ScriptStack.php',
            'lib/VM/TypeCheck.php',
            'lib/VM/Variable.php',
            'lib/Web/ConstStringFolder.php',
            'lib/Web/DeployRoot.php',
            'lib/Web/IncludePathResolver.php',
            'lib/Web/LiteralIncludeDiscovery.php',
            'lib/Web/SourceBundler.php',
            'lib/Web/Superglobals.php',
        ];
        $this->assertSame($expected, $rels);
    }
}
