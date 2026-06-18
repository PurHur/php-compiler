<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\BackedEnum;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * canonicalCaseVariable() must upgrade legacy backing scalars in the constants table (#7134).
 */
final class VmEnumCanonicalCaseVariableTest extends TestCase
{
    public function testFromReturnsEnumCaseWhenConstantsTableStoresBackingScalar(): void
    {
        $runtime = new Runtime();
        $runtime->run($runtime->parseAndCompile(<<<'PHP'
<?php
enum E: string { case A = 'x'; case B = 'y'; }
PHP, 'enum_decl.php'));
        $enum = $runtime->vmContext->classes['e'];
        $scalar = new Variable(Variable::TYPE_STRING);
        $scalar->string('x');
        $enum->constants['a'] = $scalar;
        unset($enum->enumCaseCanonicalNames['a']);

        ob_start();
        $runtime->run($runtime->parseAndCompile(<<<'PHP'
<?php
var_export(E::from('x'));
echo "\n";
PHP, 'enum_from.php'));
        $output = ob_get_clean();

        $this->assertSame("\\E::A\n", $output);
        $canonical = BackedEnum::canonicalCaseVariable($enum, 'A');
        $this->assertNotNull($canonical);
        $this->assertTrue(EnumCaseSupport::isEnumCaseVariable($canonical));
    }

    /** Issue #9603 — from()/tryFrom() must resolve via constants when enumCases table is empty. */
    public function testFromResolvesWhenEnumCasesTableEmpty(): void
    {
        $runtime = new Runtime();
        $runtime->run($runtime->parseAndCompile(<<<'PHP'
<?php
enum E: string { case A = 'x'; case B = 'y'; }
PHP, 'enum_decl.php'));
        $enum = $runtime->vmContext->classes['e'];
        $this->assertNotEmpty($enum->enumCases);
        $enum->enumCases = [];

        ob_start();
        $runtime->run($runtime->parseAndCompile(<<<'PHP'
<?php
echo E::tryFrom('x')->name;
echo E::from('y')->name;
PHP, 'enum_from_empty_cases.php'));
        $output = ob_get_clean();

        $this->assertSame('AB', $output);
    }
}
