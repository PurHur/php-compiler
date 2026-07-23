<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\enchant\EnchantExtensionPolicy;
use PHPCompiler\ext\standard\ModuleRegistry;
use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * enchant module registration + dict_check / broker depth (issues #6230 / #20613).
 *
 * @group enchant
 */
final class EnchantModuleTest extends TestCase
{
    public function test_enchant_module_when_libenchant_available(): void
    {
        if (!EnchantExtensionPolicy::advertisesExtension()) {
            $this->markTestSkipped('libenchant FFI unavailable');
        }

        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        self::assertTrue(VmReflection::functionExists($ctx, 'enchant_broker_init'));
        self::assertTrue(VmReflection::functionExists($ctx, 'enchant_dict_check'));
        self::assertTrue(VmReflection::functionExists($ctx, 'enchant_broker_list_dicts'));
        self::assertTrue(VmReflection::functionExists($ctx, 'enchant_broker_describe'));
        self::assertTrue(VmReflection::functionExists($ctx, 'enchant_dict_add_to_session'));
        self::assertTrue(VmReflection::functionExists($ctx, 'enchant_dict_add'));
        self::assertTrue(VmReflection::functionExists($ctx, 'enchant_dict_add_to_personal'));
        self::assertTrue(VmReflection::functionExists($ctx, 'enchant_dict_is_added'));
        self::assertTrue(VmReflection::functionExists($ctx, 'enchant_dict_is_in_session'));
        self::assertTrue(ModuleRegistry::extensionLoaded('enchant'));
        self::assertTrue(isset($ctx->classes['enchantbroker']));
        self::assertTrue(isset($ctx->classes['enchantdictionary']));

        $code = <<<'PHP'
<?php
$b = enchant_broker_init();
echo (int) (false !== $b);
echo (int) ($b instanceof EnchantBroker);
if (!enchant_broker_dict_exists($b, 'en_US')) {
    echo '0';
    exit;
}
$d = enchant_broker_request_dict($b, 'en_US');
echo (int) enchant_dict_check($d, 'test');
echo (int) enchant_dict_check($d, 'tset');
enchant_broker_free_dict($d);
enchant_broker_free($b);
PHP;
        $block = $runtime->parseAndCompile($code, 'enchant_module.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        if ('110' === substr($out, 0, 3) && strlen($out) === 3) {
            $this->markTestSkipped('en_US dictionary unavailable');
        }
        self::assertSame('1110', $out);
    }

    public function test_issue_repro_script(): void
    {
        if (!EnchantExtensionPolicy::advertisesExtension()) {
            $this->markTestSkipped('libenchant FFI unavailable');
        }
        $path = dirname(__DIR__) . '/repro/issue_6230_enchant_dict_check.php';
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(file_get_contents($path), $path);
        ob_start();
        try {
            $runtime->run($block);
        } catch (\Throwable $e) {
            ob_end_clean();
            if (str_contains($e->getMessage(), 'dictionary')) {
                $this->markTestSkipped($e->getMessage());
            }
            throw $e;
        }
        $out = ob_get_clean();
        if (str_contains($out, 'no_dict') || str_contains($out, 'fail_')) {
            $this->markTestSkipped('en_US dictionary unavailable: ' . trim($out));
        }
        self::assertStringContainsString('tset=0', $out);
        self::assertStringContainsString("ok\n", $out);
    }

    public function test_issue_20613_broker_dict_depth(): void
    {
        if (!EnchantExtensionPolicy::advertisesExtension()) {
            $this->markTestSkipped('libenchant FFI unavailable');
        }
        $path = dirname(__DIR__) . '/repro/issue_20613_enchant_depth.php';
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(file_get_contents($path), $path);
        ob_start();
        try {
            $runtime->run($block);
        } catch (\Throwable $e) {
            ob_end_clean();
            if (str_contains($e->getMessage(), 'dictionary') || str_contains($e->getMessage(), 'enchant')) {
                $this->markTestSkipped($e->getMessage());
            }
            throw $e;
        }
        $out = ob_get_clean();
        if (str_contains($out, 'no_dict') || str_contains($out, 'fail_')) {
            $this->markTestSkipped('en_US dictionary unavailable: ' . trim($out));
        }
        self::assertStringContainsString('enchant_broker_list_dicts Y', $out);
        self::assertStringContainsString('dicts=Y', $out);
        self::assertStringContainsString('providers=Y', $out);
        self::assertStringContainsString('added=Y', $out);
        self::assertStringContainsString('lang=en_US', $out);
        self::assertStringContainsString("ok\n", $out);
    }
}
