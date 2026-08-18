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
            'strncmp',
            'memset',
            'memcpy',
            'memcmp',
            'strcmp',
            'strtol',
            'strtod',
            'strlen',
            'snprintf',
            'malloc',
            'realloc',
            'free',
            '__phpc_resolve_stream',
        ];
    }

    public function testLibcExternDropsDeadDecls(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        foreach ($this->deletedDecls() as $sym) {
            $this->assertStringNotContainsString(
                "'{$sym}' =>",
                $source,
                "LibcExtern must not declare libc {$sym} (#28850/#29050/#30332/#31374/#31403/#31458/#31498/#31519/#31534/#31558/#31582/#31606/#31637/#31655/#31682/#31706/#31743/#31764/#31787/#31817/#31839/#31863/#31885/#31954/#31971/#31988/#31997/#32068/#32092/#32273/#32287)"
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
        $this->assertStringContainsString('#31839', $source);
        $this->assertStringContainsString('#31863', $source);
        $this->assertStringContainsString('#31885', $source);
        $this->assertStringContainsString('#31954', $source);
        $this->assertStringContainsString('#31971', $source);
        $this->assertStringContainsString('#31988', $source);
        $this->assertStringContainsString('#31997', $source);
        $this->assertStringContainsString('#32068', $source);
        $this->assertStringContainsString('#32092', $source);
        $this->assertStringContainsString('#32273', $source);
        $this->assertStringContainsString('#32287', $source);
        $this->assertStringContainsString('ensureMemmoveDecl', $source);
        $this->assertStringContainsString('ensureMemsetDecl', $source);
        $this->assertStringContainsString('ensureMemcpyDecl', $source);
        $this->assertStringContainsString('ensureMemcmpDecl', $source);
        $this->assertStringContainsString('ensureStrcmpDecl', $source);
        $this->assertStringContainsString('ensureStrtolDecl', $source);
        $this->assertStringContainsString('ensureStrtodDecl', $source);
        $this->assertStringContainsString('ensureStrlenDecl', $source);
        $this->assertStringContainsString('ensureSnprintf', $source);
        $this->assertStringContainsString('ensureMallocFamily', $source);
        $this->assertStringContainsString('ensureResolveStreamDecl', $source);
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
        $this->assertStringNotContainsString(
            "'strncmp' =>",
            $source,
            'LibcExtern must not declare libc strncmp (#31839)'
        );
        $this->assertStringNotContainsString(
            "'memset' =>",
            $source,
            'LibcExtern must not declare libc memset (#31863)'
        );
        $this->assertStringNotContainsString(
            "'memcpy' =>",
            $source,
            'LibcExtern must not declare libc memcpy (#31885)'
        );
        $this->assertStringNotContainsString(
            "'memcmp' =>",
            $source,
            'LibcExtern must not declare libc memcmp (#31954)'
        );
        $this->assertStringNotContainsString(
            "'strcmp' =>",
            $source,
            'LibcExtern must not declare libc strcmp (#31971)'
        );
        $this->assertStringNotContainsString(
            "'strtol' =>",
            $source,
            'LibcExtern must not declare libc strtol (#31988)'
        );
        $this->assertStringNotContainsString(
            "'strtod' =>",
            $source,
            'LibcExtern must not declare libc strtod (#31997)'
        );
        $this->assertStringNotContainsString(
            "'strlen' =>",
            $source,
            'LibcExtern must not declare libc strlen (#32068)'
        );
        $this->assertStringNotContainsString(
            "'snprintf' =>",
            $source,
            'LibcExtern must not declare libc snprintf (#32092)'
        );
        $this->assertStringNotContainsString(
            "'malloc' =>",
            $source,
            'LibcExtern must not declare libc malloc (#32273)'
        );
        $this->assertStringNotContainsString(
            "'realloc' =>",
            $source,
            'LibcExtern must not declare libc realloc (#32273)'
        );
        $this->assertStringNotContainsString(
            "'free' =>",
            $source,
            'LibcExtern must not declare libc free (#32273)'
        );
        $this->assertStringNotContainsString(
            "'__phpc_resolve_stream' =>",
            $source,
            'LibcExtern must not always-declare __phpc_resolve_stream (#32287)'
        );
        $this->assertStringContainsString('#32287', $source);
        $this->assertStringContainsString('#31655', $source);
        $this->assertStringContainsString('#31682', $source);
        $this->assertStringContainsString('#31706', $source);
        $this->assertStringContainsString('#31743', $source);
        $this->assertStringContainsString('#31764', $source);
        $this->assertStringContainsString('#31787', $source);
        $this->assertStringContainsString('#31817', $source);
        $this->assertStringContainsString('#31839', $source);
        $this->assertStringContainsString('#31863', $source);
        $this->assertStringContainsString('#31885', $source);
        $this->assertStringContainsString('#31954', $source);
        $this->assertStringContainsString('#31971', $source);
        $this->assertStringContainsString('#31997', $source);
        $this->assertStringContainsString('#32068', $source);
        $this->assertStringContainsString('#32092', $source);
        $this->assertStringContainsString('#32273', $source);
        $this->assertStringContainsString('#32287', $source);
        $this->assertStringContainsString('ensurePrintf', $source);
        $this->assertStringContainsString('ensureMemmoveDecl', $source);
        $this->assertStringContainsString('ensureMemsetDecl', $source);
        $this->assertStringContainsString('ensureMemcpyDecl', $source);
        $this->assertStringContainsString('ensureMemcmpDecl', $source);
        $this->assertStringContainsString('ensureStrcmpDecl', $source);
        $this->assertStringContainsString('ensureStrtodDecl', $source);
        $this->assertStringContainsString('ensureStrlenDecl', $source);
        $this->assertStringContainsString('ensureSnprintf', $source);
        $this->assertStringContainsString('ensureMallocFamily', $source);
        $this->assertStringContainsString('ensureResolveStreamDecl', $source);
        $this->assertStringContainsString('ensureStdioFile', $source);
        $this->assertStringContainsString('ensurePosixFd', $source);
        $this->assertStringContainsString('ensureStrncmp', $source);
    }

    public function testM5TrivialEchoNativeDeclaresStrncmpModuleLocallyAfterLibcExternDrop(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/M5TrivialEchoNative.php');
        $this->assertStringContainsString('ensureStrncmp', $source);
        $this->assertStringContainsString('#31839', $source);
        $this->assertStringContainsString("lookupFunction('strncmp')", $source);
        $this->assertStringContainsString('LibcExtern::ensureStrncmp', $source);
        $this->assertStringNotContainsString(
            "'strncmp' =>",
            (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php')
        );
    }

    public function testNestedJitConsumersEnsureStrncmpAfterLibcExternDrop(): void
    {
        foreach ([
            'ext/standard/JitMultipartKernel.php',
            'ext/standard/JitSuperglobalRefreshKernel.php',
            'ext/standard/JitRequestParseBodyKernel.php',
            'ext/standard/JitSessionStorageKernel.php',
            'lib/JIT/M5TrivialEchoNative.php',
            'ext/standard/JitStreamIoKernel.php',
            'ext/standard/JitStreamMetaThinAot.php',
            'ext/standard/JitPath.php',
        ] as $rel) {
            $source = (string) file_get_contents(__DIR__.'/../../'.$rel);
            $this->assertStringContainsString(
                'LibcExtern::ensureStrncmp',
                $source,
                "{$rel} must call LibcExtern::ensureStrncmp after #31839/#32382"
            );
            $this->assertTrue(
                str_contains($source, '#31839') || str_contains($source, '#32382'),
                "{$rel} must cite #31839 or #32382"
            );
        }
        $module = (string) file_get_contents(__DIR__.'/../../ext/standard/Module.php');
        $this->assertStringNotContainsString("lookupFunction('strncmp')", $module);
        $this->assertStringNotContainsString("addFunction('strncmp'", $module);
        $this->assertStringContainsString('#32382', $module);
        $this->assertStringContainsString('#31839', $module);

        $root = dirname(__DIR__, 2);
        $missing = [];
        foreach (['lib', 'ext'] as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ('php' !== $file->getExtension()) {
                    continue;
                }
                $path = $file->getPathname();
                if (str_ends_with($path, '/lib/JIT/LibcExtern.php')) {
                    continue;
                }
                $source = (string) file_get_contents($path);
                if (!str_contains($source, "lookupFunction('strncmp')")) {
                    continue;
                }
                if (!str_contains($source, 'LibcExtern::ensureStrncmp')
                    && !str_contains($source, 'self::ensureStrncmp')) {
                    $missing[] = substr($path, strlen($root) + 1);
                }
            }
        }
        $this->assertSame([], $missing, 'NestedJIT strncmp lookups must call ensureStrncmp (#32382)');
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

    public function testNestedJitConsumersEnsureMemsetDeclAfterLibcExternDrop(): void
    {
        foreach ([
            'ext/standard/JitFsGlobKernel.php',
            'ext/standard/JitGethostnameKernel.php',
            'ext/standard/JitGcCollectCyclesStandaloneKernel.php',
            'lib/JIT/Builtin/StringZlibJit.php',
        ] as $rel) {
            $source = (string) file_get_contents(__DIR__.'/../../'.$rel);
            $this->assertStringContainsString(
                'LibcExtern::ensureMemsetDecl',
                $source,
                "{$rel} must call LibcExtern::ensureMemsetDecl after #31863"
            );
            $this->assertStringContainsString('#31863', $source);
        }
        $libc = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringContainsString('ensureMemsetDecl', $libc);
        $this->assertStringContainsString('#31863', $libc);
        $this->assertStringNotContainsString("'memset' =>", $libc);
    }

    public function testNestedJitConsumersEnsureMemcpyDeclAfterLibcExternDrop(): void
    {
        foreach ([
            'ext/standard/JitMultipartKernel.php',
            'ext/standard/JitSessionStorageKernel.php',
            'lib/JIT/Builtin/SocketPairIoRuntime.php',
            'lib/JIT/Builtin/MsgRuntime.php',
            'lib/JIT/Builtin/ShmopRuntime.php',
            'lib/JIT/Builtin/StringStrtokJit.php',
            'lib/JIT/Builtin/UndefinedVariableRuntime.php',
            'lib/JIT/Builtin/UndefinedGlobalVariableRuntime.php',
            'ext/standard/JitParseStrUserScriptCstrKernel.php',
            'ext/standard/JitProgressNoteKernel.php',
            'ext/standard/JitEnvLocalKernel.php',
            'lib/JIT/Builtin/PackArgvSerialize.php',
        ] as $rel) {
            $source = (string) file_get_contents(__DIR__.'/../../'.$rel);
            if ('lib/JIT/Builtin/PackArgvSerialize.php' === $rel) {
                $this->assertTrue(
                    str_contains($source, 'LibcExtern::ensureMemcpyDecl')
                    || str_contains($source, 'LibcExtern::ensureMemcpyImplemented'),
                    "{$rel} must call LibcExtern memcpy helper after #31885"
                );
            } else {
                $this->assertStringContainsString(
                    'LibcExtern::ensureMemcpyDecl',
                    $source,
                    "{$rel} must call LibcExtern::ensureMemcpyDecl after #31885"
                );
            }
            $this->assertStringContainsString('#31885', $source);
        }
        $libc = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringContainsString('ensureMemcpyDecl', $libc);
        $this->assertStringContainsString('#31885', $libc);
        $this->assertStringNotContainsString("'memcpy' =>", $libc);
    }

    public function testNestedJitConsumersEnsureMemcmpDeclAfterLibcExternDrop(): void
    {
        foreach ([
            'lib/VM/VmStringCompare.php',
            'ext/standard/JitExplode.php',
            'ext/standard/JitSpaceshipCompareKernel.php',
            'lib/JIT/Builtin/StringStrstr.php',
            'lib/JIT/Builtin/StringStrpos.php',
            'lib/JIT/Builtin/StringStrContains.php',
            'ext/standard/JitStreamResourceKernel.php',
        ] as $rel) {
            $source = (string) file_get_contents(__DIR__.'/../../'.$rel);
            $this->assertStringContainsString(
                'LibcExtern::ensureMemcmpDecl',
                $source,
                "{$rel} must call LibcExtern::ensureMemcmpDecl after #31954"
            );
            $this->assertStringContainsString('#31954', $source);
        }
        $libc = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringContainsString('ensureMemcmpDecl', $libc);
        $this->assertStringContainsString('#31954', $libc);
        $this->assertStringNotContainsString("'memcmp' =>", $libc);
        $module = (string) file_get_contents(__DIR__.'/../../ext/standard/Module.php');
        $this->assertStringNotContainsString("lookupFunction('memcmp')", $module);
        $this->assertStringNotContainsString("addFunction('memcmp'", $module);
        $this->assertStringContainsString('#31954', $module);
        $zend = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ZendDoubleStringRuntime.php');
        $this->assertStringNotContainsString("'memcmp' =>", $zend);
        $this->assertStringContainsString('#31954', $zend);
    }

    public function testNestedJitConsumersEnsureStrcmpDeclAfterLibcExternDrop(): void
    {
        foreach ([
            'lib/VM/VmValueCompare.php',
            'ext/standard/JitFsGlobKernel.php',
            'ext/standard/JitStreamIoKernel.php',
            'ext/standard/JitGetObjectVarsNative.php',
            'ext/standard/JitLibcryptKernel.php',
            'ext/standard/JitInfo.php',
            'ext/standard/JitMinMax.php',
            'ext/standard/JitPasswordAlgo.php',
            'ext/hash/JitHashCryptoKernel.php',
            'lib/JIT/Call/ReflectionMethodInvoke.php',
            'lib/JIT/Builtin/ReflectionEnumJitHelper.php',
            'lib/JIT/Builtin/ReflectionFunctionVariadicLookupRuntime.php',
            'lib/JIT/Builtin/ParamSensitiveLookupRuntime.php',
            'lib/JIT/Builtin/CliArgvRuntime.php',
            'lib/JIT/Builtin/ReflectionNamedArgumentsLookupRuntime.php',
        ] as $rel) {
            $source = (string) file_get_contents(__DIR__.'/../../'.$rel);
            $this->assertStringContainsString(
                'LibcExtern::ensureStrcmpDecl',
                $source,
                "{$rel} must call LibcExtern::ensureStrcmpDecl after #31971"
            );
            $this->assertStringContainsString('#31971', $source);
        }
        $libc = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringContainsString('ensureStrcmpDecl', $libc);
        $this->assertStringContainsString('#31971', $libc);
        $this->assertStringNotContainsString("'strcmp' =>", $libc);
        $module = (string) file_get_contents(__DIR__.'/../../ext/standard/Module.php');
        $this->assertStringNotContainsString("lookupFunction('strcmp')", $module);
        $this->assertStringNotContainsString("addFunction('strcmp'", $module);
        $this->assertStringContainsString('#31971', $module);
    }

    public function testNestedJitConsumersEnsureStrtolDeclAfterLibcExternDrop(): void
    {
        foreach ([
            'lib/JIT/Builtin/Type/HashTable.php',
            'lib/JIT/HashTableWriteLlvm.php',
            'lib/JIT/HashTableMergeLlvm.php',
            'lib/VM/VmValueCompare.php',
            'lib/VM/VmUnaryPlus.php',
            'lib/JIT/JitLongArg.php',
            'lib/JIT/M5TrivialEchoNative.php',
            'lib/JIT/Builtin/SscanfStrtolApply.php',
            'lib/JIT/Builtin/BackedEnumFromRuntime.php',
            'ext/standard/JitChr.php',
            'ext/standard/JitIntdiv.php',
            'ext/standard/JitSleep.php',
            'ext/standard/JitFdiv.php',
            'ext/standard/JitImageTypeArg.php',
            'ext/standard/JitScalarEnumCoerce.php',
            'ext/standard/JitZendScalarCast.php',
            'ext/standard/intval.php',
            'ext/standard/JitSessionStorageKernel.php',
            'ext/filter/JitFilter.php',
        ] as $rel) {
            $source = (string) file_get_contents(__DIR__.'/../../'.$rel);
            $this->assertStringContainsString(
                'LibcExtern::ensureStrtolDecl',
                $source,
                "{$rel} must call LibcExtern::ensureStrtolDecl after #31988"
            );
            $this->assertStringContainsString('#31988', $source);
        }
        $libc = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringContainsString('ensureStrtolDecl', $libc);
        $this->assertStringContainsString('#31988', $libc);
        $this->assertStringNotContainsString("'strtol' =>", $libc);
        $module = (string) file_get_contents(__DIR__.'/../../ext/standard/Module.php');
        $this->assertStringNotContainsString("addFunction('strtol'", $module);
        $this->assertStringContainsString('#31988', $module);
    }

    public function testNestedJitConsumersEnsureStrtodDeclAfterLibcExternDrop(): void
    {
        foreach ([
            'lib/VM/VmValueCompare.php',
            'ext/standard/JitFdiv.php',
            'ext/filter/JitFilter.php',
            'ext/standard/JitZendScalarCast.php',
            'ext/standard/JitScalarEnumCoerce.php',
            'ext/standard/JitMinMax.php',
            'ext/standard/floatval.php',
            'ext/standard/is_numeric.php',
            'lib/JIT/TypedParamCoerce.php',
            'lib/JIT/ArrayUniqueLlvm.php',
            'ext/standard/JitWebParams.php',
        ] as $rel) {
            $source = (string) file_get_contents(__DIR__.'/../../'.$rel);
            $this->assertStringContainsString(
                'LibcExtern::ensureStrtodDecl',
                $source,
                "{$rel} must call LibcExtern::ensureStrtodDecl after #31997"
            );
            $this->assertStringContainsString('#31997', $source);
        }
        $libc = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringContainsString('ensureStrtodDecl', $libc);
        $this->assertStringContainsString('#31997', $libc);
        $this->assertStringNotContainsString("'strtod' =>", $libc);
        $module = (string) file_get_contents(__DIR__.'/../../ext/standard/Module.php');
        $this->assertStringNotContainsString("addFunction('strtod'", $module);
        $this->assertStringContainsString('#31997', $module);
    }

    public function testNestedJitConsumersEnsureStrlenDeclAfterLibcExternDrop(): void
    {
        foreach ([
            'ext/standard/JitEnvLocalKernel.php',
            'ext/standard/JitParseStrUserScriptCstrKernel.php',
            'ext/standard/JitMultipartKernel.php',
            'ext/standard/JitSessionStorageKernel.php',
            'lib/JIT/Builtin/CliArgvRuntime.php',
            'lib/JIT/Builtin/GetcwdJit.php',
            'lib/JIT/Builtin/ObOutputJitBridge.php',
            'lib/JIT/Builtin/VarFetchRuntime.php',
            'lib/JIT/JitReferencableCheck.php',
            'lib/JIT/BootstrapCompileSmokeM3Emit.php',
            'ext/types/strlen.php',
        ] as $rel) {
            $source = (string) file_get_contents(__DIR__.'/../../'.$rel);
            if ('ext/types/strlen.php' === $rel) {
                $this->assertStringContainsString('JitStrlen::lowerLength', $source);
                $this->assertStringNotContainsString("lookupFunction('strlen')", $source);

                continue;
            }
            $this->assertStringContainsString(
                'LibcExtern::ensureStrlenDecl',
                $source,
                "{$rel} must call LibcExtern::ensureStrlenDecl after #32068"
            );
            $this->assertStringContainsString('#32068', $source);
        }
        $libc = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringContainsString('ensureStrlenDecl', $libc);
        $this->assertStringContainsString('#32068', $libc);
        $this->assertStringNotContainsString("'strlen' =>", $libc);
        $module = (string) file_get_contents(__DIR__.'/../../ext/standard/Module.php');
        $this->assertStringNotContainsString("addFunction('strlen'", $module);
        $this->assertStringContainsString('#32068', $module);

        $root = dirname(__DIR__, 2);
        $missing = [];
        foreach (['lib', 'ext'] as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ('php' !== $file->getExtension()) {
                    continue;
                }
                $path = $file->getPathname();
                if (str_ends_with($path, '/lib/JIT/LibcExtern.php')) {
                    continue;
                }
                $source = (string) file_get_contents($path);
                if (!str_contains($source, "lookupFunction('strlen')")) {
                    continue;
                }
                if (!str_contains($source, 'LibcExtern::ensureStrlenDecl')) {
                    $missing[] = substr($path, strlen($root) + 1);
                }
            }
        }
        $this->assertSame([], $missing, 'NestedJIT strlen lookups must call ensureStrlenDecl (#32068)');
    }

    public function testNestedJitConsumersEnsureSnprintfAfterLibcExternDrop(): void
    {
        foreach ([
            'lib/JIT/Builtin/SprintfSnprintfRuntime.php',
            'lib/JIT/Builtin/NumberFormatRuntime.php',
            'lib/JIT/Builtin/ZendDoubleStringRuntime.php',
            'ext/standard/JitDate.php',
            'ext/standard/JitNlLanginfo.php',
            'ext/standard/JitBuiltinWarning.php',
            'ext/standard/decoct.php',
            'ext/standard/dechex.php',
            'ext/standard/decbin.php',
            'lib/JIT/M5TrivialEchoNative.php',
            'lib/JIT/Call/WeakMapMethod.php',
            'lib/JIT/Builtin/TypeErrorRaise.php',
        ] as $rel) {
            $source = (string) file_get_contents(__DIR__.'/../../'.$rel);
            $this->assertStringContainsString(
                'LibcExtern::ensureSnprintf',
                $source,
                "{$rel} must call LibcExtern::ensureSnprintf after #32092"
            );
            $this->assertStringContainsString('#32092', $source);
        }
        $libc = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringContainsString('ensureSnprintf', $libc);
        $this->assertStringContainsString('#32092', $libc);
        $this->assertStringNotContainsString("'snprintf' =>", $libc);

        $root = dirname(__DIR__, 2);
        $missing = [];
        foreach (['lib', 'ext'] as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ('php' !== $file->getExtension()) {
                    continue;
                }
                $path = $file->getPathname();
                if (str_ends_with($path, '/lib/JIT/LibcExtern.php')) {
                    continue;
                }
                $source = (string) file_get_contents($path);
                if (!str_contains($source, "lookupFunction('snprintf')")) {
                    continue;
                }
                if (!str_contains($source, 'LibcExtern::ensureSnprintf')) {
                    $missing[] = substr($path, strlen($root) + 1);
                }
            }
        }
        $this->assertSame([], $missing, 'NestedJIT snprintf lookups must call ensureSnprintf (#32092)');
    }

    public function testNestedJitConsumersEnsureMallocFamilyAfterLibcExternDrop(): void
    {
        foreach ([
            'lib/JIT/Builtin/MemoryManager/Native.php',
            'lib/JIT/Builtin/PackArgvSerialize.php',
            'lib/JIT/Builtin/PregExpandRuntime.php',
            'lib/JIT/Builtin/ObStorageLlvm.php',
            'lib/JIT/Builtin/OutputRewriteVarsStorage.php',
            'lib/JIT/Builtin/SocketPairIoRuntime.php',
            'lib/JIT/Builtin/SscanfAssignApply.php',
            'lib/JIT/Builtin/StringGetenv.php',
            'lib/JIT/Builtin/StringSodiumAead.php',
            'lib/JIT/Builtin/StringSodiumGenerichash.php',
            'lib/JIT/Builtin/StringZlibJit.php',
            'ext/standard/JitFsGlobKernel.php',
            'ext/standard/JitMultipartKernel.php',
            'ext/standard/JitParseStrUserScriptCstrKernel.php',
            'ext/standard/JitStreamIoKernel.php',
            'ext/standard/JitGzStreamKernel.php',
            'ext/standard/JitEnvLocalKernel.php',
            'ext/standard/JitCliProcessTitle.php',
            'ext/standard/JitRequestParseBodyKernel.php',
            'ext/standard/JitArgon2Kernel.php',
            'ext/hash/JitHashCryptoKernel.php',
            'ext/openssl/JitOpensslCipherKernel.php',
        ] as $rel) {
            $source = (string) file_get_contents(__DIR__.'/../../'.$rel);
            $this->assertStringContainsString(
                'LibcExtern::ensureMallocFamily',
                $source,
                "{$rel} must call LibcExtern::ensureMallocFamily after #32273"
            );
            $this->assertStringContainsString('#32273', $source);
        }
        $libc = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringContainsString('ensureMallocFamily', $libc);
        $this->assertStringContainsString('#32273', $libc);
        $this->assertStringNotContainsString("'malloc' =>", $libc);
        $this->assertStringNotContainsString("'realloc' =>", $libc);
        $this->assertStringNotContainsString("'free' =>", $libc);

        $root = dirname(__DIR__, 2);
        $missing = [];
        foreach (['lib', 'ext'] as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ('php' !== $file->getExtension()) {
                    continue;
                }
                $path = $file->getPathname();
                if (str_ends_with($path, '/lib/JIT/LibcExtern.php')) {
                    continue;
                }
                $source = (string) file_get_contents($path);
                if (!str_contains($source, "lookupFunction('malloc')")
                    && !str_contains($source, "lookupFunction('realloc')")
                    && !str_contains($source, "lookupFunction('free')")) {
                    continue;
                }
                if (!str_contains($source, 'LibcExtern::ensureMallocFamily')) {
                    $missing[] = substr($path, strlen($root) + 1);
                }
            }
        }
        $this->assertSame([], $missing, 'NestedJIT malloc/realloc/free lookups must call ensureMallocFamily (#32273)');
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

    public function testNestedJitStreamLeavesEnsureResolveStreamAfterLibcExternDrop(): void
    {
        foreach ([
            'ext/standard/JitStreamIoKernel.php',
            'ext/standard/JitStreamSyncKernel.php',
            'lib/JIT/Builtin/StreamGlobalsJit.php',
        ] as $rel) {
            $source = (string) file_get_contents(__DIR__.'/../../'.$rel);
            $this->assertStringContainsString(
                'LibcExtern::ensureResolveStreamDecl',
                $source,
                "{$rel} must call LibcExtern::ensureResolveStreamDecl after #32287"
            );
            $this->assertStringContainsString('#32287', $source);
        }
        $libc = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringContainsString('ensureResolveStreamDecl', $libc);
        $this->assertStringContainsString('#32287', $libc);
        $this->assertStringNotContainsString("'__phpc_resolve_stream' =>", $libc);

        $root = dirname(__DIR__, 2);
        $missing = [];
        foreach (['lib', 'ext'] as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ('php' !== $file->getExtension()) {
                    continue;
                }
                $path = $file->getPathname();
                if (str_ends_with($path, '/lib/JIT/LibcExtern.php')) {
                    continue;
                }
                $source = (string) file_get_contents($path);
                if (!str_contains($source, "lookupFunction('__phpc_resolve_stream')")) {
                    continue;
                }
                if (!str_contains($source, 'LibcExtern::ensureResolveStreamDecl')) {
                    $missing[] = substr($path, strlen($root) + 1);
                }
            }
        }
        $this->assertSame([], $missing, 'NestedJIT __phpc_resolve_stream lookups must call ensureResolveStreamDecl (#32287)');
    }
}
