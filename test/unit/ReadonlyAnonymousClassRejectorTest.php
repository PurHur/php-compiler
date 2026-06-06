<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\ReadonlyAnonymousClassRejector;
use PHPCompiler\Runtime;

/** @covers issue #6903 — new readonly class rejected at parse time */
final class ReadonlyAnonymousClassRejectorTest extends TestCase
{
    public function testNewReadonlyClassIsRejected(): void
    {
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage('syntax error, unexpected token "readonly"');

        ReadonlyAnonymousClassRejector::reject(
            '<?php $o = new readonly class { public string $x = "a"; };',
            'test.php'
        );
    }

    public function testNamedReadonlyClassIsAllowed(): void
    {
        $code = '<?php readonly class R { public int $x; }';
        self::assertSame($code, ReadonlyAnonymousClassRejector::reject($code, 'test.php'));
    }

    public function testPerPropertyReadonlyAnonymousClassIsAllowed(): void
    {
        $code = '<?php $o = new class { public readonly int $x = 1; };';
        self::assertSame($code, ReadonlyAnonymousClassRejector::reject($code, 'test.php'));
    }

    public function testOrdinaryAnonymousClassIsAllowed(): void
    {
        $code = '<?php $o = new class { public int $x = 1; };';
        self::assertSame($code, ReadonlyAnonymousClassRejector::reject($code, 'test.php'));
    }

    public function testRuntimeParseAndCompileRejectsNewReadonlyClass(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$o = new readonly class {
    public string $x = 'a';
};
echo $o->x, "\n";
PHP;
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage('syntax error, unexpected token "readonly"');
        $runtime->parseAndCompile($code, 'readonly_anon.php');
    }
}
