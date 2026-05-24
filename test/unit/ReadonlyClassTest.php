<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #1360 */
final class ReadonlyClassTest extends TestCase
{
    public function testReadonlyClassAllowsAssignDuringConstruct(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
readonly class Box {
    public int $v;
    public function __construct(int $n) {
        $this->v = $n;
    }
}
echo (new Box(7))->v;
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'readonly_construct.php'));
        $this->assertSame('7', ob_get_clean());
    }

    public function testReadonlyClassRejectsAssignAfterConstruct(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
readonly class Box {
    public int $v;
    public function __construct() {
        $this->v = 1;
    }
}
$o = new Box();
$o->v = 2;
PHP;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot modify readonly property Box::$v');
        $runtime->run($runtime->parseAndCompile($code, 'readonly_after.php'));
    }

    public function testReadonlyClassWithoutConstructorMarksConstructedOnNew(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
readonly class Box {
    public int $v;
}
$o = new Box();
$o->v = 2;
PHP;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot modify readonly property Box::$v');
        $runtime->run($runtime->parseAndCompile($code, 'readonly_no_ctor.php'));
    }

    public function testReadonlyClassFlagFromPhpCfg(): void
    {
        $this->assertTrue(\PHPCompiler\VM\ClassReadonly::fromClassFlags(
            \PhpParser\Node\Stmt\Class_::MODIFIER_READONLY
        ));
        $parser = new \PHPCfg\Parser((new \PhpParser\ParserFactory())->create(\PhpParser\ParserFactory::PREFER_PHP7));
        $nodes = (new \PhpParser\ParserFactory())->create(\PhpParser\ParserFactory::PREFER_PHP7)
            ->parse('<?php readonly class R {}');
        $this->assertInstanceOf(\PhpParser\Node\Stmt\Class_::class, $nodes[0]);
        $this->assertTrue(\PHPCompiler\VM\ClassReadonly::fromClassFlags($nodes[0]->flags));
    }
}
