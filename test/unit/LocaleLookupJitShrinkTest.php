<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\intl\LocaleLookupJitHelper;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** locale_lookup() JIT routes through JitLocaleLookup / LocaleLookupJitHelper (#32118). */
final class LocaleLookupJitShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitLocaleLookupNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/intl/locale_lookup.php');
        $this->assertStringContainsString('JitLocaleLookup::lookup', $builtin);
        $this->assertStringNotContainsString('not implemented; use VM', $builtin);

        $lowering = (string) file_get_contents(__DIR__.'/../../ext/intl/JitLocaleLookup.php');
        $this->assertStringContainsString('LocaleLookupRuntime::invoke', $lowering);
        $this->assertStringContainsString('VmLocale::lookup', $lowering);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/LocaleLookupRuntime.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $runtime);
        $this->assertStringContainsString('lookupArgv', $runtime);
        $this->assertStringContainsString('__phpc_jit_locale_lookup', $runtime);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $runtime);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $runtime);

        $method = (string) file_get_contents(__DIR__.'/../../ext/intl/LocaleLookup.php');
        $this->assertStringContainsString('JitLocaleLookup::lookup', $method);

        $ctx = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("functionProxies['locale::lookup']", $ctx);
    }

    public function testJitHelperDelegatesToVmLocale(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/intl/LocaleLookupJitHelper.php');
        $this->assertStringContainsString('VmLocale::lookup', $source);
        $this->assertStringContainsString('LocaleLookup::exportStringList', $source);
    }

    public function testSpineBundleIncludesLocaleLookupJit(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitLocaleLookup.php', $spine);
        $this->assertStringContainsString('LocaleLookupJitHelper.php', $spine);
        $this->assertStringContainsString('LocaleLookupRuntime.php', $spine);
    }

    public function testJitHelperLookupArgvMatchesPhpSrc(): void
    {
        $ht = new HashTable();
        $deDe = new Variable();
        $deDe->string('de-DE');
        $ht->append($deDe);
        $de = new Variable();
        $de->string('de');
        $ht->append($de);

        $this->assertSame('de', LocaleLookupJitHelper::lookupArgv($ht, 'de-CH', 1, 'en', 1));
        $this->assertSame(
            'en_US',
            LocaleLookupJitHelper::lookupArgv($ht, 'fr-CH', 0, 'en_US', 1)
        );
    }
}
