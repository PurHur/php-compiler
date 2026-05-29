<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** @covers issue #3083 — Enum_ ctor implements[] must match Parser.php */
final class PhpCfgEnumImplementsParseTest extends TestCase
{
    public function testBackedEnumWithImplementsParsesWithoutTypeError(): void
    {
        $parser = new \PHPCfg\Parser((new \PhpParser\ParserFactory())->create(\PhpParser\ParserFactory::PREFER_PHP7));
        $script = $parser->parse(
            <<<'PHP'
<?php
interface Labeled {}

enum Status: string implements Labeled {
    case Active = 'active';
}
PHP,
            'enum_implements.php'
        );

        $enumStmt = null;
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof \PHPCfg\Op\Stmt\Enum_) {
                $enumStmt = $child;
                break;
            }
        }
        $this->assertNotNull($enumStmt);
        $this->assertIsArray($enumStmt->implements);
        $this->assertInstanceOf(\PHPCfg\Op\Type\Literal::class, $enumStmt->backedType);
        $this->assertSame('string', $enumStmt->backedType->name);
    }
}
