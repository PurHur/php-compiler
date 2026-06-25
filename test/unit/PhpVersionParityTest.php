<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\InfoJitHelper;
use PHPCompiler\ext\standard\VmInfo;
use PHPCompiler\ext\standard\VmPhpCoreConstants;
use PHPUnit\Framework\TestCase;

/** phpversion() and PHP_VERSION must agree (#11470, ext/standard/info.c). */
final class PhpVersionParityTest extends TestCase
{
    public function testVmPhpVersionMatchesPhpVersionConstant(): void
    {
        $constant = VmPhpCoreConstants::fetch('PHP_VERSION');
        $this->assertNotNull($constant);
        $this->assertSame(CompilerVersion::VERSION, $constant->toString());
        $this->assertSame(VmInfo::phpversion(), $constant->toString());
        $this->assertSame(0, version_compare(VmInfo::phpversion(), $constant->toString()));
    }

    public function testInfoJitHelperUsesCompilerVersion(): void
    {
        $this->assertSame(CompilerVersion::VERSION, InfoJitHelper::phpversion(null));
        $this->assertStringNotContainsString(
            'VERSION_STRING',
            (string) file_get_contents(__DIR__.'/../../ext/standard/InfoJitHelper.php')
        );
    }
}
