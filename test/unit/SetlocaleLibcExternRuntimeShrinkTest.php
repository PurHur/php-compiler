<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Canonical setlocale(3) decl via LibcExtern — LocaleStartupRuntime only (#36074 / #30789).
 *
 * Prevents setlocale.1 mint from ad-hoc module-local decls (#31894 / #32122 class).
 * User-script setlocale() stays VmLocalePure (php-src ext/standard/locale.c).
 */
final class SetlocaleLibcExternRuntimeShrinkTest extends TestCase
{
    public function testLibcExternOwnsSetlocaleDecl(): void
    {
        $libc = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringContainsString('#36074', $libc);
        $this->assertStringContainsString('ensureSetlocaleDecl', $libc);
        $this->assertStringNotContainsString("'setlocale' =>", $libc);
        $pos = strpos($libc, 'function ensureSetlocaleDecl');
        $this->assertNotFalse($pos);
        $next = strpos($libc, 'public static function ensureStrlenDecl', $pos);
        $this->assertNotFalse($next);
        $body = substr($libc, $pos, $next - $pos);
        $this->assertStringContainsString('tryGetRegisteredFunction', $body);
        $this->assertStringNotContainsString('$context->lookupFunction', $body);
    }

    public function testLocaleStartupDelegatesToLibcExtern(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/LocaleStartupRuntime.php');
        $this->assertStringContainsString('#36074', $runtime);
        $this->assertStringContainsString('LibcExtern::ensureSetlocaleDecl', $runtime);
        $this->assertStringNotContainsString('function ensureSetlocaleDecl', $runtime);
        $this->assertStringNotContainsString("addFunction('setlocale'", $runtime);
    }

    public function testContextLookupFunctionLazyEnsuresSetlocale(): void
    {
        $context = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $pos = strpos($context, 'public function lookupFunction');
        $this->assertNotFalse($pos);
        $next = strpos($context, 'public function registerFunction', $pos);
        $this->assertNotFalse($next);
        $body = substr($context, $pos, $next - $pos);
        $this->assertStringContainsString("'setlocale' === \$name", $body);
        $this->assertStringContainsString('LibcExtern::ensureSetlocaleDecl($this)', $body);
        $this->assertStringContainsString('#36074', $context);
    }

    public function testNoNewRuntimeCForSetlocale(): void
    {
        $runtimeDir = dirname(__DIR__, 2).'/lib/AOT/runtime';
        $this->assertFileDoesNotExist($runtimeDir.'/setlocale.c');
        $this->assertFileDoesNotExist($runtimeDir.'/phpc_setlocale.c');
    }
}
