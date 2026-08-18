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

    public function testFloatFormattersReuseNamedSnprintfNotSnprintfDotOne(): void
    {
        $files = [
            __DIR__.'/../../lib/JIT/Builtin/ZendDoubleStringRuntime.php',
            __DIR__.'/../../lib/JIT/Builtin/SprintfSnprintfRuntime.php',
            __DIR__.'/../../lib/JIT/Builtin/NumberFormatRuntime.php',
            __DIR__.'/../../lib/JIT/Builtin/BackedEnumFromRuntime.php',
        ];
        foreach ($files as $path) {
            $src = (string) file_get_contents($path);
            $this->assertStringContainsString('LibcExtern::ensureSnprintf', $src, $path);
            $this->assertStringContainsString('#32122', $src, $path);
            $this->assertStringNotContainsString(
                "'snprintf' => [\$i32, true, [\$charPtr, \$sizeT, \$charPtr]]",
                $src,
                $path
            );
        }
        $enum = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/BackedEnumFromRuntime.php');
        $this->assertStringNotContainsString("['snprintf', \$i32, [\$charPtr, \$sizeT, \$charPtr]]", $enum);
    }

    public function testGcInternalsRegisterExistingNamedFunctions(): void
    {
        $gc = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GcCollectCyclesRuntime.php');
        $this->assertStringContainsString('$context->registerFunction($name, $fn);', $gc);
        $this->assertStringNotContainsString(
            "if (null !== \$context->module->getNamedFunction(\$name)) {\n                continue;",
            $gc
        );
        $standalone = (string) file_get_contents(
            __DIR__.'/../../ext/standard/JitGcCollectCyclesStandaloneKernel.php'
        );
        $this->assertStringContainsString('$context->registerFunction($name, $fn);', $standalone);
        $parseUrl = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ParseUrlRuntime.php');
        $this->assertStringContainsString('getNamedFunction($name)', $parseUrl);
        $this->assertStringContainsString('$context->registerFunction($name, $fn);', $parseUrl);
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
