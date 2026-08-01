<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Ast\ReadonlyClassTraitPropertyCompileCheck;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #26592 */
final class ReadonlyClassTraitPropertyCompileCheckTest extends TestCase
{
    public function testNonReadonlyTraitPropertyFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            ReadonlyClassTraitPropertyCompileCheck::messageFor('R', 'T', 'x')
        );
        $runtime->parseAndCompile(<<<'PHP'
<?php
trait T { public int $x; }
readonly class R { use T; }
PHP
            ,
            'readonly_trait_prop.php'
        );
    }

    public function testStaticTraitPropertyFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            ReadonlyClassTraitPropertyCompileCheck::messageFor('R', 'T', 'x')
        );
        $runtime->parseAndCompile(<<<'PHP'
<?php
trait T { public static int $x; }
readonly class R { use T; }
PHP
            ,
            'readonly_trait_static.php'
        );
    }

    public function testPromotedNonReadonlyFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            ReadonlyClassTraitPropertyCompileCheck::messageFor('R', 'T', 'x')
        );
        $runtime->parseAndCompile(<<<'PHP'
<?php
trait T { public function __construct(public int $x) {} }
readonly class R { use T; }
PHP
            ,
            'readonly_trait_promoted.php'
        );
    }

    public function testNestedTraitPropertyAttributedToUsedTrait(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            ReadonlyClassTraitPropertyCompileCheck::messageFor('R', 'T', 'x')
        );
        $runtime->parseAndCompile(<<<'PHP'
<?php
trait Inner { public int $x; }
trait T { use Inner; }
readonly class R { use T; }
PHP
            ,
            'readonly_nested_trait_prop.php'
        );
    }

    public function testNamespacedNamesInMessage(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            ReadonlyClassTraitPropertyCompileCheck::messageFor('N\\R', 'N\\T', 'x')
        );
        $runtime->parseAndCompile(<<<'PHP'
<?php
namespace N;
trait T { public int $x; }
readonly class R { use T; }
PHP
            ,
            'readonly_ns_trait_prop.php'
        );
    }

    public function testMethodOnlyTraitStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
trait T { public function f(): void {} }
readonly class R { use T; }
PHP
            ,
            'readonly_trait_method_ok.php'
        );
        $this->assertNotNull($block);
    }

    public function testReadonlyTraitPropertyStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
trait T { public readonly int $x; }
readonly class R { use T; }
PHP
            ,
            'readonly_trait_readonly_prop_ok.php'
        );
        $this->assertNotNull($block);
    }

    public function testPromotedReadonlyStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
trait T { public function __construct(public readonly int $x) {} }
readonly class R { use T; }
PHP
            ,
            'readonly_trait_promoted_ro_ok.php'
        );
        $this->assertNotNull($block);
    }

    public function testNonReadonlyClassAcceptsNonReadonlyTraitProp(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
trait T { public int $x; }
class C { use T; }
PHP
            ,
            'non_readonly_class_trait_ok.php'
        );
        $this->assertNotNull($block);
    }
}
