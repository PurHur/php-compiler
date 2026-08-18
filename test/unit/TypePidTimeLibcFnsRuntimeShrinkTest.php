<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on libc time/pid/strerror/passwd from Builtin\Type (#32217).
 *
 * NestedJIT leaves already declare those symbols module-locally.
 * User-script time()/getmypid()/posix_get*()/socket_strerror() stay PHP-in-PHP.
 */
final class TypePidTimeLibcFnsRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedLibcFns(): array
    {
        return [
            'time',
            'gettimeofday',
            'getpid',
            'getppid',
            'strerror',
            'getgid',
            'getuid',
            'geteuid',
            'getpwuid',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnPidTimeLibc(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32217', $type);
        $this->assertStringContainsString('#32202', $type);
        foreach ($this->droppedLibcFns() as $sym) {
            $this->assertStringNotContainsString(
                "ensureExternalFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-declare libc {$sym} (#32217)"
            );
            $this->assertStringNotContainsString(
                "'{$sym}' =>",
                $type,
                "Builtin\\Type must not always-declare libc {$sym} in a table (#32217)"
            );
        }
        $this->assertStringContainsString("'__compiler_env_local_lookup' =>", $type);
        $this->assertStringContainsString("'__compiler_env_register_putenv' =>", $type);
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
    }

    public function testNestedJitConsumersEnsureDroppedLibcBeforeLookup(): void
    {
        $ensures = [
            'time' => ['ensureLibcTime'],
            'gettimeofday' => ['ensureLibcGettimeofday'],
            'getpid' => ['ensureLibcGetpid'],
            'getppid' => ['ensureLibcGetppid'],
            'getgid' => ['ensureLibcGetgid'],
            'getuid' => ['ensureLibcGetuid'],
            'geteuid' => ['ensureLibcGeteuid'],
            'getpwuid' => ['ensureLibcGetpwuid'],
            'strerror' => ['ensureStrerrorLibc', "'strerror',", "['strerror'"],
        ];
        $root = dirname(__DIR__, 2);
        $skip = [
            $root.'/lib/JIT/LibcExtern.php',
            $root.'/lib/JIT/Builtin/Type.php',
        ];
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
                if (in_array($path, $skip, true)) {
                    continue;
                }
                $source = (string) file_get_contents($path);
                foreach ($ensures as $sym => $needles) {
                    if (!str_contains($source, "lookupFunction('{$sym}')")
                        && !str_contains($source, 'lookupFunction("'.$sym.'")')) {
                        continue;
                    }
                    $ok = false;
                    foreach ($needles as $needle) {
                        if (str_contains($source, $needle)) {
                            $ok = true;
                            break;
                        }
                    }
                    if (!$ok) {
                        $missing[] = substr($path, strlen($root) + 1).':'.$sym;
                    }
                }
            }
        }
        $this->assertSame([], $missing, 'NestedJIT lookups must declare dropped Type libc module-locally (#32217)');
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertStringContainsString(
            'TimeJitHelper',
            (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringTime.php')
        );
        $this->assertStringContainsString(
            'GetmypidJitHelper',
            (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ProcessIdentityJit.php')
        );
        $this->assertStringContainsString(
            'PosixGetpidJitHelper',
            (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PosixGetpidJit.php')
        );
        $this->assertStringContainsString(
            'SocketErrorJitHelper',
            (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SocketErrorRuntime.php')
        );
        $this->assertStringContainsString(
            'ensureLibcGeteuid',
            (string) file_get_contents(__DIR__.'/../../ext/standard/JitGetCurrentUser.php')
        );
        $this->assertStringContainsString(
            'ensureLibcGetpwuid',
            (string) file_get_contents(__DIR__.'/../../ext/standard/JitGetCurrentUser.php')
        );
    }
}
