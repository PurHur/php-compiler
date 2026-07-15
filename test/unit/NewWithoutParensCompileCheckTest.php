<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\NewWithoutParensCompileCheck;
use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** `new` in property initializers and class constants rejected (#10391, #12940, Zend/zend_compile.c). */
final class NewWithoutParensCompileCheckTest extends TestCase
{
    public function testClassConstNewWithoutParensCompileErrors(): void
    {
        if (CompilerVersion::supportsNewWithoutParensInConstAndStaticInitializers()) {
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    const X = new stdClass;
}
PHP, 'class_const_new_without_parens.php');
            $this->assertNotNull($block);

            return;
        }
        $this->expectCompileError(<<<'PHP'
<?php
class C {
    const X = new stdClass;
}
PHP);
    }

    public function testStaticPropertyDefaultNewWithoutParensCompileErrors(): void
    {
        if (CompilerVersion::supportsStaticPropertyDefaultObjectExpressions()) {
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public static $s = new stdClass;
}
PHP, 'static_property_default_new_without_parens.php');
            $this->assertNotNull($block);

            return;
        }
        $this->expectCompileError(<<<'PHP'
<?php
class C {
    public static $s = new stdClass;
}
PHP);
    }

    public function testStaticTypedPropertyDefaultNewCompileErrors(): void
    {
        if (CompilerVersion::supportsStaticPropertyDefaultObjectExpressions()) {
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public static DateTime $d = new DateTime('2020-01-01');
}
PHP, 'static_property_default_new_with_parens.php');
            $this->assertNotNull($block);

            return;
        }
        $this->expectCompileError(<<<'PHP'
<?php
class C {
    public static DateTime $d = new DateTime('2020-01-01');
}
PHP);
    }

    public function testInstanceTypedPropertyDefaultNewCompileErrors(): void
    {
        if (CompilerVersion::supportsPropertyDefaultObjectExpressions()) {
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public DateTime $d = new DateTime('2020-01-01');
}
PHP, 'property_default_new_instance_typed.php');
            $this->assertNotNull($block);

            return;
        }
        $this->expectCompileError(<<<'PHP'
<?php
class C {
    public DateTime $d = new DateTime('2020-01-01');
}
PHP);
    }

    public function testInstancePropertyDefaultNewWithoutParensCompileErrors(): void
    {
        if (CompilerVersion::supportsPropertyDefaultObjectExpressions()) {
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public $p = new stdClass();
}
PHP, 'property_default_new_instance_untyped.php');
            $this->assertNotNull($block);

            return;
        }
        $this->expectCompileError(<<<'PHP'
<?php
class C {
    public $p = new stdClass;
}
PHP);
    }

    public function testInstancePropertyDefaultNewWithParensCompileErrors(): void
    {
        if (CompilerVersion::supportsPropertyDefaultObjectExpressions()) {
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public $p = new stdClass();
}
PHP, 'property_default_new_with_parens.php');
            $this->assertNotNull($block);

            return;
        }
        $this->expectCompileError(<<<'PHP'
<?php
class C {
    public $p = new stdClass();
}
PHP);
    }

    public function testPromotedPropertyDefaultNewWithParensStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public function __construct(public stdClass $p = new stdClass()) {}
}
PHP, 'promoted_new_with_parens.php');
        $this->assertNotNull($block);
    }

    public function testClassConstNewWithParensCompileErrors(): void
    {
        if (CompilerVersion::supportsClassConstObjectExpressions()) {
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public function __construct(public int $n = 0) {}
}
class Holder {
    public const X = new C(1);
}
PHP, 'class_const_new_with_parens.php');
            $this->assertNotNull($block);

            return;
        }
        $this->expectCompileError(<<<'PHP'
<?php
class C {
    public function __construct(public int $n = 0) {}
}
class Holder {
    public const X = new C(1);
}
PHP);
    }

    public function testClassConstNewEmptyArgsWithParensCompileErrors(): void
    {
        if (CompilerVersion::supportsClassConstObjectExpressions()) {
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public const X = new stdClass();
}
PHP, 'class_const_new_stdclass.php');
            $this->assertNotNull($block);

            return;
        }
        $this->expectCompileError(<<<'PHP'
<?php
class C {
    public const X = new stdClass();
}
PHP);
    }

    public function testClassConstArrayWithNewCompileErrors(): void
    {
        $this->expectCompileError(<<<'PHP'
<?php
class C {
    public function __construct(public int $n = 0) {}
}
class Holder {
    public const X = [new C()];
}
PHP);
    }

    public function testAsymmetricVisibilityLiteralDefaultCompilesOn84Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            if (!CompilerVersion::supportsAsymmetricVisibility()) {
                $this->markTestSkipped('requires PHP_COMPILER_PROFILE=8.4 asymmetric visibility gate');
            }
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public (private(set)) string $x = 'hi';
    public (private(set)) int $n = 1;
}
PHP, 'asymmetric_visibility_literal_default.php');
            $this->assertNotNull($block);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testAsymmetricVisibilityPropertyDefaultNewCompilesOn84Profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            if (!CompilerVersion::supportsAsymmetricVisibility()
                || !CompilerVersion::supportsPropertyDefaultObjectExpressions()) {
                $this->markTestSkipped('requires PHP 8.4 forward profile gates');
            }
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public (private(set)) stdClass $obj = new stdClass();
}
PHP, 'asymmetric_visibility_property_default_new.php');
            $this->assertNotNull($block);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    private function expectCompileError(string $code): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(NewWithoutParensCompileCheck::MESSAGE);
        $runtime->parseAndCompile($code, 'new_without_parens.php');
    }
}
