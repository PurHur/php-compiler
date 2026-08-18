<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Ast\BreakContinueOperandCompileCheck;
use PHPCompiler\Compiler\CompileFatal;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class BreakContinueOperandCompileCheckTest extends TestCase
{
    public function testContinueZeroIsCompileFatal(): void
    {
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(BreakContinueOperandCompileCheck::CONTINUE_MESSAGE);
        $this->traverse(<<<'PHP'
<?php
for ($i = 0; $i < 1; $i++) {
    continue 0;
}
PHP
        );
    }

    public function testBreakZeroIsCompileFatal(): void
    {
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(BreakContinueOperandCompileCheck::BREAK_MESSAGE);
        $this->traverse(<<<'PHP'
<?php
for ($i = 0; $i < 1; $i++) {
    break 0;
}
PHP
        );
    }

    public function testContinueNegativeIsCompileFatal(): void
    {
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(BreakContinueOperandCompileCheck::CONTINUE_MESSAGE);
        $this->traverse(<<<'PHP'
<?php
for ($i = 0; $i < 1; $i++) {
    continue -1;
}
PHP
        );
    }

    public function testContinueOneInsideLoopIsAllowed(): void
    {
        $this->traverse(<<<'PHP'
<?php
for ($i = 0; $i < 1; $i++) {
    continue 1;
}
PHP
        );
        $this->addToAssertionCount(1);
    }

    public function testBareContinueDoesNotUsePositiveIntegerRule(): void
    {
        $this->traverse("<?php\ncontinue;\n");
        $this->addToAssertionCount(1);
    }

    public function testContinueOverdeepIsCompileFatal(): void
    {
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(BreakContinueOperandCompileCheck::overdeepMessage('continue', 2));
        $this->traverse(<<<'PHP'
<?php
for ($i = 0; $i < 1; $i++) {
    continue 2;
}
PHP
        );
    }

    public function testBreakOverdeepIsCompileFatal(): void
    {
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(BreakContinueOperandCompileCheck::overdeepMessage('break', 2));
        $this->traverse(<<<'PHP'
<?php
for ($i = 0; $i < 1; $i++) {
    break 2;
}
PHP
        );
    }

    public function testContinueThreeOverdeepUsesDepthInMessage(): void
    {
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(BreakContinueOperandCompileCheck::overdeepMessage('continue', 3));
        $this->traverse(<<<'PHP'
<?php
for ($i = 0; $i < 1; $i++) {
    for ($j = 0; $j < 1; $j++) {
        continue 3;
    }
}
PHP
        );
    }

    public function testContinueTwoInsideNestedLoopsIsAllowed(): void
    {
        $this->traverse(<<<'PHP'
<?php
for ($i = 0; $i < 1; $i++) {
    for ($j = 0; $j < 1; $j++) {
        continue 2;
    }
}
PHP
        );
        $this->addToAssertionCount(1);
    }

    public function testContinueTwoInsideLoopAndSwitchIsAllowed(): void
    {
        $this->traverse(<<<'PHP'
<?php
foreach ([1] as $x) {
    switch ($x) {
        case 1:
            continue 2;
    }
}
PHP
        );
        $this->addToAssertionCount(1);
    }

    public function testContinueFloatIsCompileFatal(): void
    {
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(BreakContinueOperandCompileCheck::CONTINUE_MESSAGE);
        $this->traverse(<<<'PHP'
<?php
for ($i = 0; $i < 1; $i++) {
    continue 1.5;
}
PHP
        );
    }

    public function testBreakFloatIsCompileFatal(): void
    {
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(BreakContinueOperandCompileCheck::BREAK_MESSAGE);
        $this->traverse(<<<'PHP'
<?php
for ($i = 0; $i < 1; $i++) {
    break 1.5;
}
PHP
        );
    }

    public function testRethrowRewritesTooHighCountWithoutStmtLeak(): void
    {
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(BreakContinueOperandCompileCheck::overdeepMessage('continue', 2));
        BreakContinueOperandCompileCheck::rethrowAsCompileFatal(
            new \LogicException('Too high of a count for Stmt_Continue'),
            'overdeep.php'
        );
    }

    public function testRethrowRewritesUnimplementedFloatLiteral(): void
    {
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(BreakContinueOperandCompileCheck::CONTINUE_MESSAGE);
        BreakContinueOperandCompileCheck::rethrowAsCompileFatal(
            new \LogicException('Unimplemented Node Value Type'),
            'float.php'
        );
    }

    private function traverse(string $code): void
    {
        $parser = (new ParserFactory())->create(ParserFactory::ONLY_PHP7);
        $ast = $parser->parse($code);
        $this->assertNotNull($ast);
        $visitor = new BreakContinueOperandCompileCheck();
        $visitor->setSourceFile('continue_zero.php');
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);
    }
}
