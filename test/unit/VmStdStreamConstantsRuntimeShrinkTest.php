<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmStdStreamConstants;
use PHPUnit\Framework\TestCase;

/** STDIN/STDOUT/STDERR registered in ext/standard PHP bootstrap, not C runtime (#10163). */
final class VmStdStreamConstantsRuntimeShrinkTest extends TestCase
{
    public function testVmStdStreamConstantsPhpBootstrap(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStdStreamConstants.php');
        $this->assertStringContainsString('VmFsStdio::open', $source);
        $this->assertStringContainsString('defineConstant', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/phpc_stdio.c');

        $module = (string) file_get_contents(__DIR__.'/../../ext/standard/Module.php');
        $this->assertStringContainsString('VmStdStreamConstants::register', $module);
    }
}
