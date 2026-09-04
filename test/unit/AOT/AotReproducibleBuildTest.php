<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPUnit\Framework\TestCase;

/**
 * #36399: deterministic AOT link / TargetMachine opt mapping (on CompileTarget).
 */
final class AotReproducibleBuildTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('SOURCE_DATE_EPOCH');
        unset($_ENV['SOURCE_DATE_EPOCH'], $_SERVER['SOURCE_DATE_EPOCH']);
        putenv('PHP_COMPILER_REPRODUCIBLE');
        unset($_ENV['PHP_COMPILER_REPRODUCIBLE'], $_SERVER['PHP_COMPILER_REPRODUCIBLE']);
        putenv('PHP_COMPILER_AOT_CODEGEN_OPT');
        unset($_ENV['PHP_COMPILER_AOT_CODEGEN_OPT'], $_SERVER['PHP_COMPILER_AOT_CODEGEN_OPT']);
        putenv('PHP_COMPILER_OPT_LEVEL');
        unset($_ENV['PHP_COMPILER_OPT_LEVEL'], $_SERVER['PHP_COMPILER_OPT_LEVEL']);
        parent::tearDown();
    }

    public function testLinkBuildIdFlagWlAndRaw(): void
    {
        $this->assertStringContainsString('--build-id=sha1', CompileTarget::linkBuildIdFlag(false));
        $this->assertStringContainsString('-Wl,--build-id=sha1', CompileTarget::linkBuildIdFlag(true));
    }

    public function testSourceDateEpochFromEnv(): void
    {
        putenv('SOURCE_DATE_EPOCH=1700000000');
        $_ENV['SOURCE_DATE_EPOCH'] = '1700000000';
        $this->assertSame('1700000000', CompileTarget::sourceDateEpoch());
        $env = CompileTarget::applySourceDateEpochToEnv(['PATH' => '/bin']);
        $this->assertSame('1700000000', $env['SOURCE_DATE_EPOCH']);
        $this->assertSame('/bin', $env['PATH']);
    }

    public function testReproducibleModeSuppliesDefaultEpoch(): void
    {
        putenv('PHP_COMPILER_REPRODUCIBLE=1');
        $_ENV['PHP_COMPILER_REPRODUCIBLE'] = '1';
        $this->assertTrue(CompileTarget::isReproducibleMode());
        $this->assertSame('1700000000', CompileTarget::sourceDateEpoch());
    }

    public function testTargetMachineOptMapsOptLevel(): void
    {
        putenv('PHP_COMPILER_OPT_LEVEL=2');
        $_ENV['PHP_COMPILER_OPT_LEVEL'] = '2';
        $this->assertSame(\PHPLLVM\Target::OPT_LEVEL_DEFAULT, CompileTarget::targetMachineOptLevel());

        putenv('PHP_COMPILER_AOT_CODEGEN_OPT=aggressive');
        $_ENV['PHP_COMPILER_AOT_CODEGEN_OPT'] = 'aggressive';
        $this->assertSame(\PHPLLVM\Target::OPT_LEVEL_AGGRESSIVE, CompileTarget::targetMachineOptLevel());
    }

    public function testSortedStrings(): void
    {
        $this->assertSame(['a', 'b', 'c'], CompileTarget::sortedStrings(['c', 'a', 'b']));
        $this->assertSame([], CompileTarget::sortedStrings([]));
    }

    public function testConfigRegistryListsReproKnobs(): void
    {
        $reg = \PHPCompiler\Config::registry();
        $this->assertArrayHasKey('PHP_COMPILER_REPRODUCIBLE', $reg);
        $this->assertSame('#36399', $reg['PHP_COMPILER_REPRODUCIBLE']['since']);
        $this->assertArrayHasKey('SOURCE_DATE_EPOCH', $reg);
        $this->assertSame('#36399', $reg['SOURCE_DATE_EPOCH']['since']);
    }
}
