<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Lint\Linter;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #1354 */
final class AttributeParseTest extends TestCase
{
    public function testClassAttributeRuns(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[\AllowDynamicProperties]
class Box {
    public function ping(): string {
        return 'pong';
    }
}
echo (new Box())->ping();
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attribute_class.php'));
        $this->assertSame('pong', ob_get_clean());
    }

    public function testMethodAttributeRuns(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Box {
    #[\Deprecated]
    public function ping(): string {
        return 'pong';
    }
}
echo (new Box())->ping();
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attribute_method.php'));
        $this->assertSame('pong', ob_get_clean());
    }

    public function testLintGreenOnDecoratedClass(): void
    {
        $linter = new Linter();
        $issues = $linter->lintSource(
            <<<'PHP'
<?php
#[\AllowDynamicProperties]
class C {
    #[\Deprecated]
    public function m(): void {}
}
PHP,
            'attribute_lint.php'
        );
        $this->assertSame([], $issues);
    }

    public function testPhpParserPreservesAttrGroups(): void
    {
        $nodes = (new \PhpParser\ParserFactory())->create(\PhpParser\ParserFactory::PREFER_PHP7)
            ->parse('<?php #[\AllowDynamicProperties] class C {}');
        $this->assertInstanceOf(\PhpParser\Node\Stmt\Class_::class, $nodes[0]);
        $this->assertNotEmpty($nodes[0]->attrGroups);
    }
}
