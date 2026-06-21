<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\EnumCasesJitHelper;
use PHPCompiler\VM\EnumSupport;
use PHPUnit\Framework\TestCase;

/** Issue #10395 — Enum::cases() SSOT shared between VM and JIT helpers. */
final class EnumCasesJitHelperTest extends TestCase
{
    public function testCasesListLengthMatchesDeclarationOrder(): void
    {
        $this->assertSame(3, EnumCasesJitHelper::casesListLength(3));
        $this->assertSame(0, EnumCasesJitHelper::casesListLength(0));
        $this->assertSame(0, EnumCasesJitHelper::casesListLength(-1));
    }

    public function testListIndexForPositionIsDenseZeroBased(): void
    {
        $this->assertSame(0, EnumCasesJitHelper::listIndexForPosition(0));
        $this->assertSame(2, EnumCasesJitHelper::listIndexForPosition(2));
    }

    public function testEnumSupportCasesListDeclarationOrder(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum Color: string { case Red = 'r'; case Green = 'g'; case Blue = 'b'; }
PHP;
        $block = $runtime->parseAndCompile($code, 'enum_cases_list_ssot.php');
        $this->assertNotNull($block);
        $runtime->run($block);
        $ctx = $runtime->vmContext;
        $this->assertNotNull($ctx);
        $entry = $ctx->classes['color'] ?? null;
        $this->assertNotNull($entry);
        $cases = EnumSupport::casesList($entry, $ctx);
        $ht = $cases->toArray();
        $this->assertSame(3, $ht->getNumElements());
        $case0 = $ht->findIndex(0);
        $case2 = $ht->findIndex(2);
        $this->assertNotNull($case0);
        $this->assertNotNull($case2);
        $this->assertSame('Red', EnumCaseSupport::enumCaseNameForVariable($case0->resolveIndirect()));
        $this->assertSame('Blue', EnumCaseSupport::enumCaseNameForVariable($case2->resolveIndirect()));
    }
}
