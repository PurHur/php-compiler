<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #9682 — Enum::cases() must work when enumCases table is empty but constants remain (#9603). */
final class VmEnumCasesEmptyTableTest extends TestCase
{
    public function testCasesReturnsCanonicalSingletonsWhenEnumCasesTableEmpty(): void
    {
        $runtime = new Runtime();
        $runtime->run($runtime->parseAndCompile(<<<'PHP'
<?php
enum E: int { case A = 1; case B = 2; }
enum U { case A; case B; }
PHP, 'enum_decl.php'));
        $backed = $runtime->vmContext->classes['e'];
        $unit = $runtime->vmContext->classes['u'];
        $this->assertNotEmpty($backed->enumCases);
        $this->assertNotEmpty($unit->enumCases);
        $backed->enumCases = [];
        $unit->enumCases = [];

        ob_start();
        $runtime->run($runtime->parseAndCompile(<<<'PHP'
<?php
echo count(E::cases());
echo count(U::cases());
echo U::cases()[0]->name;
echo (E::cases()[0] === E::A) ? '1' : '0';
PHP, 'enum_cases_empty_table.php'));
        $output = ob_get_clean();

        $this->assertSame('22A1', $output);
    }
}
