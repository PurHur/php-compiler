<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * LibcExtern drops dead FS/string/process decls after helper migrations (#28850, #29050).
 *
 * Peer of {@see LibcExternMathRuntimeShrinkTest} (#28808).
 */
final class LibcExternDeadDeclsRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function deletedDecls(): array
    {
        return [
            'calloc',
            'strspn',
            'strcspn',
            'strpbrk',
            'strncpy',
            'utime',
            'chown',
            'fchownat',
            'getgrnam',
            'getpwnam',
            'remove',
            'pipe',
            'fork',
            'dup2',
            'waitpid',
            'flock',
            'fsync',
            'fdatasync',
            'sscanf',
            'strtok_r',
            'rename',
            'chdir',
            'time',
            'chmod',
            'mkdir',
            'stat',
            'access',
            'lstat',
            'strrchr',
            'strcoll',
            'strchr',
            'strstr',
            'realpath',
            'strdup',
            'setenv',
            'unsetenv',
            'putenv',
            'fflush',
            'ferror',
            'fgets',
            'popen',
            'pclose',
            'fileno',
            'getenv',
            'mkstemp',
            'memchr',
            'strncasecmp',
            'printf',
            'memmove',
            'fopen',
            'fread',
            'fwrite',
            'fclose',
            'strcasecmp',
            'open',
            'close',
            'read',
            'write',
        ];
    }

    public function testLibcExternDropsDeadDecls(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        foreach ($this->deletedDecls() as $sym) {
            $this->assertStringNotContainsString(
                "'{$sym}' =>",
                $source,
                "LibcExtern must not declare libc {$sym} (#28850/#29050/#30332/#31374/#31403/#31458/#31498/#31519/#31534/#31558/#31582/#31606/#31637/#31655/#31682/#31706/#31743/#31764/#31787/#31817)"
            );
        }
        $this->assertStringContainsString('#28850', $source);
        $this->assertStringContainsString('#29050', $source);
        $this->assertStringContainsString('#30332', $source);
        $this->assertStringContainsString('#31374', $source);
        $this->assertStringContainsString('#31403', $source);
        $this->assertStringContainsString('#31458', $source);
        $this->assertStringContainsString('#31498', $source);
        $this->assertStringContainsString('#31519', $source);
        $this->assertStringContainsString('#31534', $source);
        $this->assertStringContainsString('#31558', $source);
        $this->assertStringContainsString('#31582', $source);
        $this->assertStringContainsString('#31606', $source);
        $this->assertStringContainsString('#31637', $source);
        $this->assertStringContainsString('#31655', $source);
        $this->assertStringContainsString('#31682', $source);
        $this->assertStringContainsString('#31706', $source);
        $this->assertStringContainsString('#31743', $source);
        $this->assertStringContainsString('#31764', $source);
        $this->assertStringContainsString('#31787', $source);
        $this->assertStringContainsString('#31817', $source);
        $this->assertStringContainsString('ensureMemmoveDecl', $source);
        $this->assertStringContainsString('ensureStdioFile', $source);
        $this->assertStringContainsString('ensurePosixFd', $source);
    }

    public function testLibcExternKeepsLiveFsAndMcjitAliases(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        foreach (['syscall', '__phpc_host_php_write', '__phpc_host_snprintf'] as $sym) {
            $this->assertStringContainsString(
                "'{$sym}' =>",
                $source,
                "LibcExtern must keep live {$sym} (#28850)"
            );
        }
        $this->assertStringNotContainsString(
            "'mkstemp' =>",
            $source,
            'LibcExtern must not declare libc mkstemp (#31655)'
        );
        $this->assertStringNotContainsString(
            "'memchr' =>",
            $source,
            'LibcExtern must not declare libc memchr (#31655)'
        );
        $this->assertStringNotContainsString(
            "'rename' =>",
            $source,
            'LibcExtern must not declare libc rename (#29090)'
        );
        $this->assertStringContainsString('#29090', $source);
        $this->assertStringNotContainsString(
            "'chdir' =>",
            $source,
            'LibcExtern must not declare libc chdir (#29219)'
        );
        $this->assertStringContainsString('#29219', $source);
        $this->assertStringNotContainsString(
            "'time' =>",
            $source,
            'LibcExtern must not declare libc time (#30332)'
        );
        $this->assertStringNotContainsString(
            "'chmod' =>",
            $source,
            'LibcExtern must not declare libc chmod (#31374)'
        );
        $this->assertStringNotContainsString(
            "'mkdir' =>",
            $source,
            'LibcExtern must not declare libc mkdir (#31374)'
        );
        $this->assertStringNotContainsString(
            "'stat' =>",
            $source,
            'LibcExtern must not declare libc stat (#31403)'
        );
        $this->assertStringNotContainsString(
            "'access' =>",
            $source,
            'LibcExtern must not declare libc access (#31403)'
        );
        $this->assertStringNotContainsString(
            "'lstat' =>",
            $source,
            'LibcExtern must not declare libc lstat (#31403)'
        );
        $this->assertStringNotContainsString(
            "'strrchr' =>",
            $source,
            'LibcExtern must not declare libc strrchr (#31458)'
        );
        $this->assertStringNotContainsString(
            "'strcoll' =>",
            $source,
            'LibcExtern must not declare libc strcoll (#31498)'
        );
        $this->assertStringNotContainsString(
            "'strchr' =>",
            $source,
            'LibcExtern must not declare libc strchr (#31519)'
        );
        $this->assertStringNotContainsString(
            "'strstr' =>",
            $source,
            'LibcExtern must not declare libc strstr (#31519)'
        );
        $this->assertStringNotContainsString(
            "'realpath' =>",
            $source,
            'LibcExtern must not declare libc realpath (#31534)'
        );
        $this->assertStringNotContainsString(
            "'strdup' =>",
            $source,
            'LibcExtern must not declare libc strdup (#31534)'
        );
        $this->assertStringNotContainsString(
            "'setenv' =>",
            $source,
            'LibcExtern must not declare libc setenv (#31558)'
        );
        $this->assertStringNotContainsString(
            "'unsetenv' =>",
            $source,
            'LibcExtern must not declare libc unsetenv (#31558)'
        );
        $this->assertStringNotContainsString(
            "'putenv' =>",
            $source,
            'LibcExtern must not declare libc putenv (#31582)'
        );
        $this->assertStringNotContainsString(
            "'fflush' =>",
            $source,
            'LibcExtern must not declare libc fflush (#31606)'
        );
        $this->assertStringNotContainsString(
            "'ferror' =>",
            $source,
            'LibcExtern must not declare libc ferror (#31606)'
        );
        $this->assertStringNotContainsString(
            "'fgets' =>",
            $source,
            'LibcExtern must not declare libc fgets (#31606)'
        );
        $this->assertStringNotContainsString(
            "'popen' =>",
            $source,
            'LibcExtern must not declare libc popen (#31606)'
        );
        $this->assertStringNotContainsString(
            "'pclose' =>",
            $source,
            'LibcExtern must not declare libc pclose (#31606)'
        );
        $this->assertStringNotContainsString(
            "'fileno' =>",
            $source,
            'LibcExtern must not declare libc fileno (#31606)'
        );
        $this->assertStringNotContainsString(
            "'getenv' =>",
            $source,
            'LibcExtern must not declare libc getenv (#31637)'
        );
        $this->assertStringNotContainsString(
            "'strncasecmp' =>",
            $source,
            'LibcExtern must not declare libc strncasecmp (#31682)'
        );
        $this->assertStringNotContainsString(
            "'printf' =>",
            $source,
            'LibcExtern must not declare libc printf (#31706)'
        );
        $this->assertStringNotContainsString(
            "'memmove' =>",
            $source,
            'LibcExtern must not declare libc memmove (#31743)'
        );
        $this->assertStringNotContainsString(
            "'fopen' =>",
            $source,
            'LibcExtern must not declare libc fopen (#31764)'
        );
        $this->assertStringNotContainsString(
            "'fread' =>",
            $source,
            'LibcExtern must not declare libc fread (#31764)'
        );
        $this->assertStringNotContainsString(
            "'fwrite' =>",
            $source,
            'LibcExtern must not declare libc fwrite (#31764)'
        );
        $this->assertStringNotContainsString(
            "'fclose' =>",
            $source,
            'LibcExtern must not declare libc fclose (#31764)'
        );
        $this->assertStringNotContainsString(
            "'strcasecmp' =>",
            $source,
            'LibcExtern must not declare libc strcasecmp (#31787)'
        );
        $this->assertStringNotContainsString(
            "'open' =>",
            $source,
            'LibcExtern must not declare libc open (#31817)'
        );
        $this->assertStringNotContainsString(
            "'close' =>",
            $source,
            'LibcExtern must not declare libc close (#31817)'
        );
        $this->assertStringNotContainsString(
            "'read' =>",
            $source,
            'LibcExtern must not declare libc read (#31817)'
        );
        $this->assertStringNotContainsString(
            "'write' =>",
            $source,
            'LibcExtern must not declare libc write (#31817)'
        );
        $this->assertStringContainsString('#31655', $source);
        $this->assertStringContainsString('#31682', $source);
        $this->assertStringContainsString('#31706', $source);
        $this->assertStringContainsString('#31743', $source);
        $this->assertStringContainsString('#31764', $source);
        $this->assertStringContainsString('#31787', $source);
        $this->assertStringContainsString('#31817', $source);
        $this->assertStringContainsString('ensurePrintf', $source);
        $this->assertStringContainsString('ensureMemmoveDecl', $source);
        $this->assertStringContainsString('ensureStdioFile', $source);
        $this->assertStringContainsString('ensurePosixFd', $source);
    }

    public function testNestedJitConsumersEnsurePrintfAfterLibcExternDrop(): void
    {
        foreach ([
            'ext/standard/JitHeader.php',
            'ext/standard/JitSetcookie.php',
            'ext/standard/JitSessionStorageKernel.php',
            'lib/JIT/Builtin/ScriptExit.php',
        ] as $rel) {
            $source = (string) file_get_contents(__DIR__.'/../../'.$rel);
            $this->assertStringContainsString(
                'LibcExtern::ensurePrintf',
                $source,
                "{$rel} must call LibcExtern::ensurePrintf after #31706"
            );
            $this->assertStringContainsString('#31706', $source);
        }
    }

    public function testNestedJitConsumersEnsureStdioFileAfterLibcExternDrop(): void
    {
        foreach ([
            'ext/standard/JitFilePutContentsLibc.php',
            'ext/standard/JitMultipartKernel.php',
            'lib/JIT/M5TrivialEchoNative.php',
        ] as $rel) {
            $source = (string) file_get_contents(__DIR__.'/../../'.$rel);
            $this->assertStringContainsString(
                'LibcExtern::ensureStdioFile',
                $source,
                "{$rel} must call LibcExtern::ensureStdioFile after #31764"
            );
            $this->assertStringContainsString('#31764', $source);
        }
        $io = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamIoKernel.php');
        $this->assertStringContainsString("['fopen'", $io);
        $this->assertStringContainsString("['fread'", $io);
        $this->assertStringContainsString("['fwrite'", $io);
        $this->assertStringContainsString("['fclose'", $io);
        $this->assertStringContainsString('#31764', $io);
    }

    public function testNestedJitConsumersEnsurePosixFdAfterLibcExternDrop(): void
    {
        foreach ([
            'ext/standard/JitFileGetContentsLibc.php',
            'ext/standard/JitReadfileLibc.php',
            'ext/standard/JitRandomBytesKernel.php',
            'ext/standard/JitGethostnameKernel.php',
            'ext/standard/JitObWriteStdoutKernel.php',
            'ext/standard/JitSessionStorageKernel.php',
            'lib/JIT/Builtin/SocketPairIoRuntime.php',
            'ext/standard/JitProgressNoteKernel.php',
            'lib/JIT/Builtin/EmbedObEchoBridge.php',
        ] as $rel) {
            $source = (string) file_get_contents(__DIR__.'/../../'.$rel);
            $this->assertStringContainsString(
                'LibcExtern::ensurePosixFd',
                $source,
                "{$rel} must call LibcExtern::ensurePosixFd after #31817"
            );
            $this->assertStringContainsString('#31817', $source);
        }
        $touch = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/TouchLibcRuntime.php');
        $this->assertStringContainsString("['open'", $touch);
        $this->assertStringContainsString("['close'", $touch);
        $this->assertStringContainsString('#31817', $touch);
        $io = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamIoKernel.php');
        $this->assertStringContainsString("['close'", $io);
        $this->assertStringContainsString('#31817', $io);
        $tempnam = (string) file_get_contents(__DIR__.'/../../ext/standard/JitTempnamKernel.php');
        $this->assertStringContainsString("['close'", $tempnam);
        $this->assertStringContainsString('#31817', $tempnam);
        $ob = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ObStorageLlvm.php');
        $this->assertStringContainsString("'write'", $ob);
        $this->assertStringContainsString('#31817', $ob);
    }

    public function testParseStrKernelDeclaresMemmoveModuleLocallyAfterLibcExternDrop(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitParseStrUserScriptCstrKernel.php');
        $this->assertStringContainsString('#31743', $source);
        $this->assertStringContainsString('LibcExtern::ensureMemmoveDecl', $source);
        $this->assertStringContainsString("lookupFunction('memmove')", $source);
        $this->assertStringNotContainsString("'memmove' =>", (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php'));
    }

    public function testNestedJitConsumersLookupCompilerStrcasecmpAfterLibcExternDrop(): void
    {
        foreach ([
            'lib/JIT/InstanceOfHelper.php',
            'lib/JIT/Call/ReflectionMethodInvoke.php',
            'lib/JIT/ClassConstFetchHelperTrait.php',
            'lib/JIT/Builtin/ClassConstFetchRuntime.php',
            'lib/JIT/Builtin/ReflectionEnumJitHelper.php',
            'lib/JIT/Builtin/SessionModuleName.php',
            'lib/JIT/Builtin/AttributeRegistryLookupRuntime.php',
            'lib/JIT/Builtin/ReflectionPropertyGetMangledNameRuntime.php',
            'lib/JIT/Builtin/ReflectionPropertyRawValueRuntime.php',
            'lib/JIT/Builtin/ReflectionPropertyIsVirtualRuntime.php',
            'lib/JIT/Builtin/ReflectionPropertyIsFinalRuntime.php',
            'ext/standard/JitIniGetAll.php',
            'ext/standard/JitIsCallable.php',
            'ext/filter/JitFilterId.php',
        ] as $rel) {
            $source = (string) file_get_contents(__DIR__.'/../../'.$rel);
            $this->assertStringContainsString(
                'StringCaseCompare::ABI_STRCASECMP',
                $source,
                "{$rel} must look up __compiler_strcasecmp after #31787"
            );
            $this->assertStringContainsString(
                'ensureStrcasecmpLinked',
                $source,
                "{$rel} must link CaseCompareJitHelper before strcasecmp lookup (#31787)"
            );
            $this->assertStringNotContainsString(
                "lookupFunction('strcasecmp')",
                $source,
                "{$rel} must not look up libc strcasecmp after #31787"
            );
        }
        $this->assertStringContainsString('#31787', (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php'));
        $this->assertStringContainsString('#31787', (string) file_get_contents(__DIR__.'/../../lib/JIT/InstanceOfHelper.php'));
    }

    public function testObjectTypeRoutesStrncasecmpThroughCompilerAbiAfterLibcExternDrop(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/Object_.php');
        $this->assertStringContainsString('#31682', $source);
        $this->assertStringContainsString('StringCaseCompare::ensureStrncasecmpLinked', $source);
        $this->assertStringContainsString('StringCaseCompare::ABI_STRNCASECMP', $source);
        $this->assertStringNotContainsString("lookupFunction('strncasecmp')", $source);
        $this->assertStringNotContainsString("addFunction('strncasecmp'", $source);
    }

    public function testJitFilterBooleanTokenUsesCompilerStrncasecmpAfterLibcExternDrop(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/filter/JitFilter.php');
        $this->assertStringContainsString('#31682', $source);
        $this->assertStringContainsString('StringCaseCompare::ensureStrncasecmpLinked', $source);
        $this->assertStringContainsString('StringCaseCompare::ABI_STRNCASECMP', $source);
        $this->assertStringNotContainsString("lookupFunction('strncasecmp')", $source);
        $this->assertStringNotContainsString('LibcExtern::register', $source);
    }

    public function testJitTempnamKernelDeclaresMkstempMemchrModuleLocallyAfterLibcExternDrop(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitTempnamKernel.php');
        $this->assertStringContainsString('#31655', $source);
        $this->assertStringContainsString("['memchr'", $source);
        $this->assertStringContainsString("['mkstemp'", $source);
        $this->assertStringContainsString("lookupFunction('mkstemp')", $source);
        $this->assertStringContainsString("lookupFunction('memchr')", $source);
    }

    public function testStreamKernelsDeclareStdioModuleLocallyAfterLibcExternDrop(): void
    {
        $io = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamIoKernel.php');
        $this->assertStringContainsString("['popen'", $io);
        $this->assertStringContainsString("['pclose'", $io);
        $this->assertStringContainsString("['fgets'", $io);
        $this->assertStringContainsString("['ferror'", $io);
        $this->assertStringContainsString('#31606', $io);
        $sync = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStreamSyncKernel.php');
        $this->assertStringContainsString("['fflush'", $sync);
        $this->assertStringContainsString("['fileno'", $sync);
        $this->assertStringContainsString('#31606', $sync);
        $ob = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ObStorageLlvm.php');
        $this->assertStringContainsString("'fflush'", $ob);
        $this->assertStringContainsString('#31606', $ob);
    }

    public function testStringGetenvDeclaresGetenvModuleLocallyAfterLibcExternDrop(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetenv.php');
        $this->assertStringContainsString('ensureLibcGetenv', $source);
        $this->assertStringContainsString('#31637', $source);
        $this->assertStringContainsString("lookupFunction('getenv')", $source);
        $this->assertMatchesRegularExpression("/addFunction\\(\\s*'getenv'/", $source);
        $bootstrap = (string) file_get_contents(__DIR__.'/../../lib/JIT/BootstrapCompileSmokeM3Emit.php');
        $this->assertStringContainsString('ensureLibcGetenv', $bootstrap);
        $this->assertStringContainsString('StringGetenv::ensureLibcGetenv', $bootstrap);
        $sys = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SysGetTempDirRuntime.php');
        $this->assertStringContainsString("['getenv', \$i8p, [\$i8p]]", $sys);
        $this->assertStringContainsString('#31637', $sys);
    }

    public function testBootstrapCompileSmokeM3EmitDeclaresPutenvModuleLocallyAfterLibcExternDrop(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/BootstrapCompileSmokeM3Emit.php');
        $this->assertStringContainsString('ensureLibcPutenv', $source);
        $this->assertStringContainsString('#31582', $source);
        $this->assertStringContainsString("lookupFunction('putenv')", $source);
        $this->assertMatchesRegularExpression("/addFunction\\(\\s*'putenv'/", $source);
    }

    public function testChownRuntimeDoesNotLookupLibcChown(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ChownRuntime.php');
        $this->assertStringContainsString('ChownJitHelper', $source);
        $this->assertStringNotContainsString("lookupFunction('chown')", $source);
        $this->assertStringNotContainsString("lookupFunction('fchownat')", $source);
        $this->assertStringNotContainsString("lookupFunction('getgrnam')", $source);
        $this->assertStringNotContainsString("lookupFunction('getpwnam')", $source);
    }

    public function testStringStrspnUsesPhpcAbiNotLibcStrspn(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrspn.php');
        $this->assertStringContainsString('phpc_strspn_extended', $source);
        $this->assertStringNotContainsString("lookupFunction('strspn')", $source);
    }

    public function testStringGetenvDeclaresStrchrModuleLocallyAfterLibcExternDrop(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetenv.php');
        $this->assertStringContainsString('ensureLibcStrchr', $source);
        $this->assertStringContainsString('#31519', $source);
        $this->assertStringContainsString("lookupFunction('strchr')", $source);
        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/JitParseStrUserScriptCstrKernel.php');
        $this->assertStringContainsString("'strchr'", $kernel);
    }

    public function testStringGetenvDeclaresSetenvUnsetenvModuleLocallyAfterLibcExternDrop(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetenv.php');
        $this->assertStringContainsString('ensureLibcSetenvUnsetenv', $source);
        $this->assertStringContainsString('#31558', $source);
        $this->assertStringContainsString("lookupFunction('setenv')", $source);
        $this->assertStringContainsString("lookupFunction('unsetenv')", $source);
        $this->assertStringContainsString('invokePutenvNestedLeaf', $source);
    }

    public function testSysGetTempDirDeclaresRealpathModuleLocallyAfterLibcExternDrop(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SysGetTempDirRuntime.php');
        $this->assertStringContainsString('ensureLibc', $source);
        $this->assertStringContainsString('#31534', $source);
        $this->assertStringContainsString("['realpath', \$i8p, [\$i8p, \$i8p]]", $source);
        $this->assertStringContainsString("lookupFunction('realpath')", $source);
        $this->assertStringNotContainsString('LibcExtern::register', $source);
    }
}
