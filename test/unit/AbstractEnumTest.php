<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\AbstractEnumSourceRewriter;
use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #26519 — abstract enum rejected (Zend/zend_language_parser.y; inverts #3737) */
final class AbstractEnumTest extends TestCase
{
    public function testAbstractEnumIsParseFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract enum E { case A; }
echo "ok\n";
PHP;
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(AbstractEnumSourceRewriter::MESSAGE);
        $runtime->parseAndCompile($code, 'abstract_enum.php');
    }

    public function testAbstractEnumRejectorMatchesZendMessage(): void
    {
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(AbstractEnumSourceRewriter::MESSAGE);
        AbstractEnumSourceRewriter::reject("<?php\nabstract enum E { case A; }\n", 't.php');
    }

    public function testPlainEnumStillWorks(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E { case A; }
echo E::A->name;
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'plain_enum.php'));
        self::assertSame('A', ob_get_clean());
    }

    public function testUnitEnumInstantiationThrowsError(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E { case A; }
new E();
PHP;
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot instantiate enum E');
        $runtime->run($runtime->parseAndCompile($code, 'unit_enum_instantiate.php'));
    }

    public function testDynamicBackedEnumInstantiationThrowsError(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum F: int { case B = 1; }
$class = F::class;
new $class();
PHP;
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot instantiate enum F');
        $runtime->run($runtime->parseAndCompile($code, 'backed_enum_dynamic.php'));
    }
}
