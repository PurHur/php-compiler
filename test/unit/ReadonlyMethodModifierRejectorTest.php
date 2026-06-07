<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\ReadonlyMethodModifierRejector;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #7183 */
final class ReadonlyMethodModifierRejectorTest extends TestCase
{
    public function testReadonlyBeforeVisibilityOnMethodIsRejected(): void
    {
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(ReadonlyMethodModifierRejector::MESSAGE);

        ReadonlyMethodModifierRejector::reject(<<<'PHP'
<?php
class C {
    readonly public function m(): void {}
}
PHP, 'test.php');
    }

    public function testReadonlyAfterVisibilityOnMethodIsRejected(): void
    {
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(ReadonlyMethodModifierRejector::MESSAGE);

        ReadonlyMethodModifierRejector::reject(<<<'PHP'
<?php
class C {
    public readonly function m(): void {}
}
PHP, 'test.php');
    }

    public function testReadonlyMethodModifierThroughRuntimeParse(): void
    {
        $runtime = new Runtime();
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage(ReadonlyMethodModifierRejector::MESSAGE);
        $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    readonly public function m(): void {}
}
PHP, 'readonly_method_modifier.php');
    }

    public function testReadonlyPropertyIsAllowed(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public readonly int $x;
}
PHP;
        self::assertSame($code, ReadonlyMethodModifierRejector::reject($code, 'test.php'));
    }

    public function testReadonlyConstructorParamIsAllowed(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public function __construct(readonly string $x) {}
}
PHP;
        self::assertSame($code, ReadonlyMethodModifierRejector::reject($code, 'test.php'));
    }

    public function testFunctionNamedReadonlyInsideClassIsAllowed(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public function readonly(): void {}
}
PHP;
        self::assertSame($code, ReadonlyMethodModifierRejector::reject($code, 'test.php'));
    }

    public function testReadonlyClassIsAllowed(): void
    {
        $code = <<<'PHP'
<?php
readonly class C {
    public function m(): void {}
}
PHP;
        self::assertSame($code, ReadonlyMethodModifierRejector::reject($code, 'test.php'));
    }
}
