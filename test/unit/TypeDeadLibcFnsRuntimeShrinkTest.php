<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Drop always-on libc getloadavg/sleep/calendar decls from Builtin\Type (#32173).
 *
 * User-script sys_getloadavg()/sleep()/mktime()/strftime() stay PHP-in-PHP.
 * NestedJIT has no lookupFunction consumers for the dropped symbols.
 */
final class TypeDeadLibcFnsRuntimeShrinkTest extends TestCase
{
    /** @return list<string> */
    private function droppedLibcFns(): array
    {
        return [
            'getloadavg',
            'sleep',
            'usleep',
            'mktime',
            'timegm',
            'localtime',
            'gmtime',
            'strftime',
            'strptime',
        ];
    }

    public function testTypeBuiltinDropsDeadAlwaysOnLibcFns(): void
    {
        $type = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('#32173', $type);
        $this->assertStringContainsString('#32139', $type);
        foreach ($this->droppedLibcFns() as $sym) {
            $this->assertStringNotContainsString(
                "'{$sym}' =>",
                $type,
                "Builtin\\Type must not always-declare libc {$sym} (#32173)"
            );
        }
        $this->assertStringContainsString("addFunction('exit'", $type);
        $this->assertStringContainsString("addFunction('abort'", $type);
    }

    public function testNoNestedJitLookupFunctionRemainsForDroppedLibcFns(): void
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
                foreach ($this->droppedLibcFns() as $sym) {
                    if (str_contains($source, "lookupFunction('{$sym}')")
                        || str_contains($source, 'lookupFunction("'.$sym.'")')) {
                        $hits[] = substr($path, strlen($root) + 1).':'.$sym;
                    }
                }
            }
        }
        $this->assertSame([], $hits, 'No NestedJIT lookupFunction may remain for dropped Type libcFns (#32173)');
    }

    public function testPhpHelpersRemainForDroppedUserScriptBuiltins(): void
    {
        $this->assertStringContainsString(
            'SysGetloadavgJitHelper',
            (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SysGetloadavgRuntime.php')
        );
        $this->assertStringContainsString(
            'SleepJitHelper',
            (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathSleep.php')
        );
        $this->assertStringContainsString(
            'MktimeJitHelper',
            (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringMktime.php')
        );
        $this->assertStringContainsString(
            'StrftimeJitHelper',
            (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrftime.php')
        );
    }
}
