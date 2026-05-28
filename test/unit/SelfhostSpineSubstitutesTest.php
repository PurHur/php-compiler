<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** M2 spine inventory substitutes (#2126, #1945). */
final class SelfhostSpineSubstitutesTest extends TestCase
{
    public function testCoverageSyncDocumentsLlvmEnvAndMacroSubstitutes(): void
    {
        $root = dirname(__DIR__, 2);
        $script = (string) file_get_contents($root.'/script/check-selfhost-spine-coverage-sync.php');
        $this->assertStringContainsString("'src/llvm-env.php' => 'test/bootstrap-aot/llvm_env_spine_shim.php'", $script);
        $this->assertStringContainsString("'src/macro_functions.php' => 'test/bootstrap-aot/macro_functions_spine_shim.php'", $script);
        $this->assertStringNotContainsString("'src/yay-php8-compat.php'", $script);
    }

    public function testSpineMainRequiresYayCompatAndShims(): void
    {
        $root = dirname(__DIR__, 2);
        $main = (string) file_get_contents($root.'/test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('src/yay-php8-compat.php', $main);
        $this->assertStringContainsString('src/cli_driver.php', $main);
        $this->assertStringContainsString('llvm_env_spine_shim.php', $main);
        $this->assertStringContainsString('macro_functions_spine_shim.php', $main);
    }
}
