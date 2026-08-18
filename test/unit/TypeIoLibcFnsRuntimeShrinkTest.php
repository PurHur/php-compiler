<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop leftover always-on libc getenv/putenv/strlen/stdio/posix-fd from Builtin\Type (#32202).
 *
 * NestedJIT leaves already declare those symbols module-locally after LibcExtern drops.
 * User-script getenv()/putenv()/strlen()/fopen() stay PHP-in-PHP.
 */
final class TypeIoLibcFnsRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedLibcFns(): array
    {
        return [
            'getenv',
            'putenv',
            'strlen',
            'open',
            'fopen',
            'fwrite',
            'fclose',
            'read',
            'lseek',
            'write',
            'close',
        ];
    }

    public function testTypeBuiltinDropsLeftoverAlwaysOnIoLibc(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32202', $type);
        $this->assertStringContainsString('#32173', $type);
        foreach ($this->droppedLibcFns() as $sym) {
            $this->assertStringNotContainsString(
                "ensureExternalFunction('{$sym}'",
                $type,
                "Builtin\\Type must not always-declare libc {$sym} (#32202)"
            );
            $this->assertStringNotContainsString(
                "'{$sym}' =>",
                $type,
                "Builtin\\Type must not always-declare libc {$sym} in a table (#32202)"
            );
        }
        $this->assertStringContainsString("'__compiler_env_local_lookup' =>", $type);
        $this->assertStringContainsString("'__compiler_env_register_putenv' =>", $type);
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
    }

    public function testNoNestedJitLookupFunctionRemainsForLseek(): void
    {
        $root = dirname(__DIR__, 2);
        $hits = [];
        foreach (['lib', 'ext'] as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ('php' !== $file->getExtension()) {
                    continue;
                }
                $path = $file->getPathname();
                $source = (string) file_get_contents($path);
                if (str_contains($source, "lookupFunction('lseek')")
                    || str_contains($source, 'lookupFunction("lseek")')) {
                    $hits[] = substr($path, strlen($root) + 1);
                }
            }
        }
        $this->assertSame([], $hits, 'No NestedJIT lookupFunction(\'lseek\') may remain (#32202)');
    }

    public function testNestedJitConsumersEnsureDroppedLibcBeforeLookup(): void
    {
        $ensures = [
            'getenv' => ['ensureLibcGetenv', "['getenv'"],
            'putenv' => ['ensureLibcPutenv', "'putenv',", "['putenv'"],
            'strlen' => ['ensureStrlenDecl'],
            'open' => ['ensurePosixFd', "['open'"],
            'read' => ['ensurePosixFd', "['read'"],
            'write' => ['ensurePosixFd', "['write'", "ensureExternal(\$context, 'write'"],
            'close' => ['ensurePosixFd', "['close'"],
            'fopen' => ['ensureStdioFile', "['fopen'"],
            'fwrite' => ['ensureStdioFile', "['fwrite'"],
            'fclose' => ['ensureStdioFile', "['fclose'"],
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
        $this->assertSame([], $missing, 'NestedJIT lookups must declare dropped Type libc module-locally (#32202)');
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertStringContainsString(
            'GetenvLookupJitHelper',
            (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetenv.php')
        );
        $this->assertStringContainsString(
            'PutenvJitHelper',
            (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetenv.php')
        );
        $this->assertStringContainsString(
            'JitStrlen::lowerLength',
            (string) file_get_contents(__DIR__.'/../../ext/types/strlen.php')
        );
        $this->assertStringContainsString(
            '__compiler_fopen',
            (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php')
        );
    }
}
