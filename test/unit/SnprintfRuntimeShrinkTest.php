<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * User-script sprintf()/printf() stay PHP-in-PHP (#32092).
 * Libc snprintf(3) is module-local after the LibcExtern always-on drop.
 */
final class SnprintfRuntimeShrinkTest extends TestCase
{
    public function testUserScriptSprintfStaysPhpNotAlwaysOnLibc(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SprintfSnprintfRuntime.php');
        $this->assertStringContainsString('LibcExtern::ensureSnprintf', $runtime);
        $this->assertStringContainsString('#32092', $runtime);
        $this->assertStringContainsString("lookupFunction('snprintf')", $runtime);
    }

    public function testLibcExternDropsAlwaysOnSnprintf(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringNotContainsString("'snprintf' =>", $source);
        $this->assertStringContainsString('ensureSnprintf', $source);
        $this->assertStringContainsString('#32092', $source);
        $this->assertStringContainsString("'__phpc_host_snprintf' =>", $source);
    }

    public function testNumberFormatAndDateRouteSnprintfThroughEnsure(): void
    {
        $number = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/NumberFormatRuntime.php');
        $this->assertStringContainsString('LibcExtern::ensureSnprintf', $number);
        $this->assertStringContainsString('#32092', $number);
        $date = (string) file_get_contents(__DIR__.'/../../ext/standard/JitDate.php');
        $this->assertStringContainsString('LibcExtern::ensureSnprintf', $date);
        $this->assertStringContainsString('#32092', $date);
        $this->assertStringNotContainsString('function ensureSnprintf', $date);
    }

    public function testWeakRefRegistryUsesCanonicalSnprintfPrototype(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/WeakRefRegistryRuntime.php');
        $this->assertStringContainsString(
            "functionType(\$i32, true, \$i8p, \$sizeT, \$i8p)",
            $source
        );
        $this->assertStringNotContainsString(
            "functionType(\$i32, true, \$i8p, \$sizeT, \$i8p, \$i8p)",
            $source
        );
    }
}
