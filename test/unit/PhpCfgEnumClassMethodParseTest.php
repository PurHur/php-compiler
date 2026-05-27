<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** @covers issue #2299 */
final class PhpCfgEnumClassMethodParseTest extends TestCase
{
    public function testEnumBodyIncludesClassMethodOp(): void
    {
        $parser = new \PHPCfg\Parser((new \PhpParser\ParserFactory())->create(\PhpParser\ParserFactory::PREFER_PHP7));
        $script = $parser->parse(
            <<<'PHP'
<?php
enum Status: string {
    case Active = 'active';
    public static function tag(): string {
        return 'ok';
    }
}
PHP,
            'enum_method.php'
        );

        $enumStmt = null;
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof \PHPCfg\Op\Stmt\Enum_) {
                $enumStmt = $child;
                break;
            }
        }
        $this->assertNotNull($enumStmt, 'Expected Stmt_Enum in main block');

        $methodOps = array_filter(
            $enumStmt->stmts->children,
            static fn ($op): bool => $op instanceof \PHPCfg\Op\Stmt\ClassMethod
        );
        $this->assertCount(1, $methodOps);
        $method = array_values($methodOps)[0];
        $this->assertSame('tag', $method->func->name);
        $this->assertNotNull($method->func->cfg, 'Enum method must have CFG body');
    }
}
