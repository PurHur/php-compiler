<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** @covers issue #2574 */
final class PhpCfgArrowFunctionParseTest extends TestCase
{
    public function testArrowFunctionParsesToCfgOp(): void
    {
        $parser = new \PHPCfg\Parser((new \PhpParser\ParserFactory())->create(\PhpParser\ParserFactory::PREFER_PHP7));
        $script = $parser->parse(
            <<<'PHP'
<?php
$fn = static fn (string $n): string => ltrim($n, '\\');
PHP,
            'arrow.php'
        );

        $this->assertCount(1, $script->functions);
        $arrowFunc = $script->functions[0];
        $this->assertSame('{anonymous}#1', $arrowFunc->name);
        $this->assertInstanceOf(\PHPCfg\Op\Expr\ArrowFunction::class, $arrowFunc->callableOp);
        $this->assertSame('Expr_ArrowFunction', $arrowFunc->callableOp->getType());

        $assignOps = array_filter(
            $script->main->cfg->children,
            static fn ($op): bool => $op instanceof \PHPCfg\Op\Expr\Assign
        );
        $this->assertCount(1, $assignOps);
    }
}
