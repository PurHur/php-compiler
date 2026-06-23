<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Compile-time CFG scans for generator / VM-fallback opcodes (issue #167). */
final class GeneratorOpcodesTest extends TestCase
{
    public function testContainsGeneratorOpcodesDetectsYieldInNestedFunction(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
function gen() {
    yield 1;
}
foreach (gen() as $v) {
    echo $v;
}
PHP
            ,
            'generator_probe.php'
        );
        $this->assertNotNull($block);
        $this->assertTrue(Block::containsGeneratorOpcodes($block));
        $this->assertFalse(Block::requiresVmLowering($block));
        $this->assertFalse(Block::containsGeneratorOpcodesInScriptScope($block));
    }

    public function testContainsGeneratorOpcodesFalseForPlainScript(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile('<?php echo 1;', 'plain.php');
        $this->assertNotNull($block);
        $this->assertFalse(Block::containsGeneratorOpcodes($block));
        $this->assertFalse(Block::requiresVmLowering($block));
    }

    public function testContainsGeneratorOpcodesInCallableBodySkipsNestedClosure(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
return (static function (): array {
    return iterator_to_array((static function (): Generator {
        yield 1;
    })());
})();
PHP
            ,
            'nested_closure_generator.php'
        );
        $findArrayClosure = static function (Block $b) use (&$findArrayClosure): ?Block {
            foreach ($b->opCodes as $op) {
                if (OpCode::TYPE_CLOSURE === $op->type && $op->block1 instanceof Block) {
                    $inner = $op->block1;
                    if (!$inner->isGenerator) {
                        return $inner;
                    }
                    $found = $findArrayClosure($inner);
                    if (null !== $found) {
                        return $found;
                    }
                }
            }

            return null;
        };
        $arrayClosure = $findArrayClosure($block);
        $this->assertNotNull($arrayClosure);
        $this->assertFalse($arrayClosure->isGenerator);
        $this->assertTrue(Block::containsGeneratorOpcodes($block));
        $this->assertFalse(Block::containsGeneratorOpcodesInCallableBody($arrayClosure));
    }

    public function testRequiresVmLoweringForSimpleTryCatch(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
try {
    echo 1;
} catch (Exception $e) {
    echo 0;
}
PHP
            ,
            'try_probe.php'
        );
        $this->assertNotNull($block);
        $this->assertFalse(Block::containsGeneratorOpcodes($block));
        $this->assertFalse(Block::containsFinallyOpcodes($block));
        $this->assertTrue(Block::requiresVmLowering($block));
    }

    public function testRequiresVmLoweringForTryFinally(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
try {
    echo 1;
} finally {
    echo 0;
}
PHP
            ,
            'try_finally_probe.php'
        );
        $this->assertNotNull($block);
        $this->assertTrue(Block::containsFinallyOpcodes($block));
        $this->assertTrue(Block::requiresVmLowering($block));
    }
}
