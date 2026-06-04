<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\compiler_is_superglobal_name;
use PHPCompiler\ext\standard\SuperglobalNames;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM/JIT builtin __compiler_is_superglobal_name() (#5391, #1056). */
final class SuperglobalNameBuiltinTest extends TestCase
{
    public function testSuperglobalNameCRuntimeRemoved(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/superglobal_name.c');
    }

    public function testSuperglobalNamesTableIncludesGlobals(): void
    {
        $this->assertContains('GLOBALS', SuperglobalNames::ALL);
        $this->assertContains('_GET', SuperglobalNames::ALL);
    }

    /**
     * @dataProvider superglobalNameCases
     */
    public function testVmBuiltinMatchesTable(string $name, bool $expected): void
    {
        $runtime = new Runtime();
        $fn = new compiler_is_superglobal_name();
        $frame = $fn->getFrame($runtime->vmContext);
        $nameVar = new VMVariable();
        $nameVar->string($name);
        $frame->calledArgs = [$nameVar];
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        $resolved = $frame->returnVar->resolveIndirect();
        $this->assertSame($expected, $resolved->toBool());
        $this->assertSame($expected, SuperglobalNames::isSuperglobalName($name));
    }

    /** @return list<array{0: string, 1: bool}> */
    public static function superglobalNameCases(): array
    {
        return [
            ['_GET', true],
            ['GLOBALS', true],
            ['_SESSION', true],
            ['not_super', false],
            ['', false],
        ];
    }
}
