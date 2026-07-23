<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\pspell\PspellExtensionPolicy;
use PHPCompiler\ext\standard\ModuleRegistry;
use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * pspell module registration + check/suggest (issue #6294).
 *
 * @group pspell
 */
final class PspellModuleTest extends TestCase
{
    public function test_pspell_module_when_libaspell_available(): void
    {
        if (!PspellExtensionPolicy::advertisesExtension()) {
            $this->markTestSkipped('libaspell FFI unavailable');
        }

        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        self::assertTrue(VmReflection::functionExists($ctx, 'pspell_new'));
        self::assertTrue(VmReflection::functionExists($ctx, 'pspell_check'));
        self::assertTrue(VmReflection::functionExists($ctx, 'pspell_suggest'));
        self::assertTrue(VmReflection::functionExists($ctx, 'pspell_add_to_session'));
        self::assertTrue(VmReflection::functionExists($ctx, 'pspell_config_create'));
        self::assertTrue(VmReflection::functionExists($ctx, 'pspell_new_config'));
        self::assertTrue(ModuleRegistry::extensionLoaded('pspell'));
        self::assertTrue(isset($ctx->classes['pspell\\dictionary']));
        self::assertTrue(isset($ctx->classes['pspell\\config']));
        self::assertSame(1, $ctx->constants['PSPELL_FAST']->toInt());
        self::assertSame(2, $ctx->constants['PSPELL_NORMAL']->toInt());
        self::assertSame(3, $ctx->constants['PSPELL_BAD_SPELLERS']->toInt());
        self::assertSame(8, $ctx->constants['PSPELL_RUN_TOGETHER']->toInt());
    }

    public function test_issue_22229_repro_existence(): void
    {
        if (!PspellExtensionPolicy::advertisesExtension()) {
            $this->markTestSkipped('libaspell FFI unavailable');
        }
        $path = dirname(__DIR__) . '/repro/pspell_personal_session_config.php';
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(file_get_contents($path), $path);
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertStringContainsString('pspell_add_to_session=Y', $out);
        self::assertStringContainsString('pspell_config_create=Y', $out);
        self::assertStringContainsString('pspell_new_config=Y', $out);
    }

    public function test_issue_repro_script(): void
    {
        if (!PspellExtensionPolicy::advertisesExtension()) {
            $this->markTestSkipped('libaspell FFI unavailable');
        }
        $path = dirname(__DIR__) . '/repro/issue_6294_pspell_check.php';
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(file_get_contents($path), $path);
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        if (str_contains($out, 'connect-fail')) {
            $this->markTestSkipped('en aspell dictionary unavailable: ' . trim($out));
        }
        self::assertStringContainsString('exists=Y', $out);
        self::assertStringContainsString('colour=1', $out);
        self::assertStringContainsString('colourr=0', $out);
        self::assertStringContainsString("ok\n", $out);
    }
}
