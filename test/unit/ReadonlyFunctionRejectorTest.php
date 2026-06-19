<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\ReadonlyFunctionRejector;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #10012 */
final class ReadonlyFunctionRejectorTest extends TestCase
{
    public function testTopLevelReadonlyFunctionIsRejected(): void
    {
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(ReadonlyFunctionRejector::MESSAGE);

        ReadonlyFunctionRejector::reject(<<<'PHP'
<?php
readonly function f(): int { return 1; }
PHP, 'test.php');
    }

    public function testReadonlyClosureExpressionIsRejected(): void
    {
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(ReadonlyFunctionRejector::MESSAGE);

        ReadonlyFunctionRejector::reject(<<<'PHP'
<?php
$f = readonly function () {};
PHP, 'test.php');
    }

    public function testReadonlyClassIsAllowed(): void
    {
        $code = <<<'PHP'
<?php
readonly class C {
    function m(): void {}
}
PHP;
        self::assertSame($code, ReadonlyFunctionRejector::reject($code, 'test.php'));
    }

    public function testReadonlyPropertyIsAllowed(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public readonly int $x;
}
PHP;
        self::assertSame($code, ReadonlyFunctionRejector::reject($code, 'test.php'));
    }

    public function testFunctionNamedReadonlyIsAllowed(): void
    {
        $code = <<<'PHP'
<?php
function readonly(): void {}
PHP;
        self::assertSame($code, ReadonlyFunctionRejector::reject($code, 'test.php'));
    }

    public function testThroughRuntimeParseAndCompile(): void
    {
        $runtime = new Runtime();
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(ReadonlyFunctionRejector::MESSAGE);
        $runtime->parseAndCompile(<<<'PHP'
<?php
readonly function f(): int { return 1; }
PHP, 'parity_readonly_function_reject.php');
    }
}
