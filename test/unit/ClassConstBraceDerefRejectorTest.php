<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ClassConstBraceDerefRejector;
use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\CompilerVersion;
use PHPUnit\Framework\TestCase;

final class ClassConstBraceDerefRejectorTest extends TestCase
{
    public function testRejectsSingleQuotedBraceDerefOnReferenceProfile(): void
    {
        if (CompilerVersion::supportsClassConstBraceDeref()) {
            $this->markTestSkipped('PHP 8.3+ allows class const brace deref');
        }

        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage('unexpected token ";"');

        ClassConstBraceDerefRejector::reject(
            "<?php\nclass C { public const X = 42; }\necho C::{'X'};\n",
            'test.php'
        );
    }

    public function testAllowsVariableBraceDerefOnReferenceProfile(): void
    {
        if (CompilerVersion::supportsClassConstBraceDeref()) {
            $this->markTestSkipped('PHP 8.3+ allows class const brace deref');
        }

        $code = "<?php echo C::{\$name};\n";
        $this->assertSame($code, ClassConstBraceDerefRejector::reject($code, 'test.php'));
    }
}
