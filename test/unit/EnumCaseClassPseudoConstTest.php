<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Enum case `::class` must yield declaring enum FQCN (Zend/zend_enum.c, #9426).
 */
final class EnumCaseClassPseudoConstTest extends TestCase
{
    public function testVarDumpEnumCaseClassReturnsFqcnString(): void
    {
        $code = <<<'PHP'
<?php
enum E: string { case A = 'a'; }
var_dump(E::A::class);
var_dump(E::A::class === E::class);
enum U { case B; }
var_dump(U::B::class);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_case_class.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertStringContainsString('string(1) "E"', $output);
        $this->assertStringContainsString('bool(true)', $output);
        $this->assertStringContainsString('string(1) "U"', $output);
    }

    public function testEchoEnumCaseClassReturnsFqcnString(): void
    {
        $code = <<<'PHP'
<?php
enum E: int { case A = 1; }
echo E::A::class, "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_case_class_echo.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame("E\n", $output);
    }
}
