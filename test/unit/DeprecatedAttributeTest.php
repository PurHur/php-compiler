<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\DeprecatedMetadata;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #3569 */
final class DeprecatedAttributeTest extends TestCase
{
    public function testFormatClassMessage(): void
    {
        $meta = new DeprecatedMetadata('Legacy API', '8.4');
        $this->assertSame(
            'Class Legacy is deprecated since 8.4, Legacy API',
            $meta->formatClass('Legacy')
        );

        $meta = new DeprecatedMetadata(null, null);
        $this->assertSame('Class Old is deprecated', $meta->formatClass('Old'));
    }

    public function testFormatTraitUseMessage(): void
    {
        $meta = new DeprecatedMetadata('old trait', null);
        $this->assertSame(
            'Trait Tr used by C is deprecated, old trait',
            $meta->formatTraitUse('Tr', 'C')
        );

        $meta = new DeprecatedMetadata(null, null);
        $this->assertSame(
            'Trait DemoTrait used by DemoClass is deprecated',
            $meta->formatTraitUse('DemoTrait', 'DemoClass')
        );
    }

    public function testFormatEnumMessages(): void
    {
        $meta = new DeprecatedMetadata('Legacy enum', '8.4');
        $this->assertSame(
            'Enum Legacy is deprecated since 8.4, Legacy enum',
            $meta->formatEnum('Legacy')
        );

        $meta = new DeprecatedMetadata('use E::Test instead', null);
        $this->assertSame(
            'Enum case E::Test2 is deprecated, use E::Test instead',
            $meta->formatEnumCase('E', 'Test2')
        );

        $meta = new DeprecatedMetadata(null, null);
        $this->assertSame('Enum case E::Test is deprecated', $meta->formatEnumCase('E', 'Test'));
    }

    public function testFormatFunctionMessage(): void
    {
        $meta = new DeprecatedMetadata('old', null);
        $this->assertSame('Function f() is deprecated, old', $meta->formatFunction('f'));

        $meta = new DeprecatedMetadata('use g() instead', '8.4');
        $this->assertSame(
            'Function f() is deprecated since 8.4, use g() instead',
            $meta->formatFunction('f')
        );

        $meta = new DeprecatedMetadata(null, '1.0');
        $this->assertSame('Function f() is deprecated since 1.0', $meta->formatFunction('f'));
    }

    /** @covers issue #26370 */
    public function testFormatPropertyHookMessage(): void
    {
        $meta = new DeprecatedMetadata(null, null);
        $this->assertSame(
            'Method C::$x::get() is deprecated',
            $meta->formatPropertyHook('C', 'x', 'get')
        );
        $this->assertSame(
            'Method C::$x::set() is deprecated',
            $meta->formatPropertyHook('C', 'x', 'set')
        );

        $meta = new DeprecatedMetadata('old', '8.4');
        $this->assertSame(
            'Method D::$x::get() is deprecated since 8.4, old',
            $meta->formatPropertyHook('D', 'x', 'get')
        );
    }

    public function testBareDeprecatedEmitsRuntimeNotice(): void
    {
        $meta = new DeprecatedMetadata(null, null);
        $this->assertTrue($meta->emitsRuntimeNotice());

        $meta = new DeprecatedMetadata('old', null);
        $this->assertTrue($meta->emitsRuntimeNotice());

        $meta = new DeprecatedMetadata(null, '1.0');
        $this->assertTrue($meta->emitsRuntimeNotice());
    }

    public function testIsDeprecatedForReflectionGatesSinceAgainstLanguageProfileVersion(): void
    {
        $meta = new DeprecatedMetadata('old fn', '8.4');
        $this->assertFalse($meta->isDeprecatedForReflection());

        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue($meta->isDeprecatedForReflection());
        } finally {
            putenv('PHP_COMPILER_PROFILE');
        }

        $meta = new DeprecatedMetadata('old fn', '8.2');
        $this->assertTrue($meta->isDeprecatedForReflection());

        $meta = new DeprecatedMetadata(null, null);
        $this->assertTrue($meta->isDeprecatedForReflection());
    }

    public function testFromDocCommentTextDetectsBareDeprecatedTag(): void
    {
        $meta = DeprecatedMetadata::fromDocCommentText("/** @deprecated */");
        $this->assertNotNull($meta);
        $this->assertTrue($meta->isDeprecatedForReflection());
    }

    public function testFromDocCommentTextParsesVersionAndMessage(): void
    {
        $meta = DeprecatedMetadata::fromDocCommentText("/** @deprecated 8.4 use Other::X */");
        $this->assertNotNull($meta);
        $this->assertSame('8.4', $meta->since);
        $this->assertSame('use Other::X', $meta->message);
    }

    public function testDeprecatedMethodAndConstantFormatMessages(): void
    {
        $meta = new DeprecatedMetadata('use g()', '8.4');
        $this->assertTrue($meta->emitsRuntimeNotice());
        $this->assertSame(
            'Method C::f() is deprecated since 8.4, use g()',
            $meta->formatMethod('C', 'f')
        );
        $this->assertSame(
            'Constant C::A is deprecated since 8.4, use g()',
            $meta->formatConstant('C', 'A')
        );
    }

    /** @covers issue #27825 */
    public function testBareDeprecatedMethodCallEmitsUnderProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
ini_set('error_reporting', '32767');
ini_set('display_errors', '0');
class Box {
    #[\Deprecated]
    public function ping(): string {
        return 'pong';
    }
}
(new Box())->ping();
$last = error_get_last();
echo ($last['message'] ?? 'none'), "\n";
echo (($last['type'] ?? 0) === 16384) ? 'dep' : 'no';
PHP;
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'bare_deprecated_method.php'));
            $this->assertSame(
                "Method Box::ping() is deprecated\ndep",
                ob_get_clean()
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #29380 */
    public function testDeprecatedInterfaceConstFetchViaImplementorUnderProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
ini_set('error_reporting', '32767');
ini_set('display_errors', '0');
interface I {
    #[\Deprecated(message: 'use Y')]
    public const X = 1;
}
class C implements I {}
echo C::X, "\n";
$last = error_get_last();
echo ($last['message'] ?? 'none'), "\n";
echo (($last['type'] ?? 0) === 16384) ? 'dep' : 'no';
PHP;
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'deprecated_iface_const.php'));
            $this->assertSame(
                "1\nConstant I::X is deprecated, use Y\ndep",
                ob_get_clean()
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #27825 */
    public function testBareDeprecatedFunctionCallEmitsUnderProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
ini_set('error_reporting', '32767');
ini_set('display_errors', '0');
#[\Deprecated]
function bare_dep(): void {}
bare_dep();
$last = error_get_last();
echo ($last['message'] ?? 'none'), "\n";
echo (($last['type'] ?? 0) === 16384) ? 'dep' : 'no';
PHP;
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'bare_deprecated_fn.php'));
            $this->assertSame(
                "Function bare_dep() is deprecated\ndep",
                ob_get_clean()
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testDeprecatedOnPropertyIsCompileFatalUnderProfile84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            if (!\PHPCompiler\CompilerVersion::advertisesDeprecatedAttributeClass()) {
                $this->markTestSkipped('Deprecated not advertised');
            }
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
class C {
    #[\Deprecated]
    public int $p = 2;
}
PHP;
            $this->expectException(\CompileError::class);
            $this->expectExceptionMessage(
                'Attribute "Deprecated" cannot target property (allowed targets: function, method, class constant)'
            );
            $runtime->parseAndCompile($code, 'bare_deprecated_property.php');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /**
     * php-src validate_deprecated: TARGET_CLASS is traits-only (#26307 / #28892).
     *
     * @dataProvider provideDeprecatedClassLikeRejectsUnderProfile85
     */
    public function testDeprecatedOnNonTraitClassLikeIsCompileFatalUnderProfile85(
        string $code,
        string $message
    ): void {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            if (!\PHPCompiler\CompilerVersion::supportsDeprecatedTraitAttribute()) {
                $this->markTestSkipped('requires PROFILE≥8.5 deprecated trait attribute');
            }
            $runtime = new Runtime();
            $this->expectException(\CompileError::class);
            $this->expectExceptionMessage($message);
            $runtime->parseAndCompile($code, 'deprecated_classlike_reject_85.php');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function provideDeprecatedClassLikeRejectsUnderProfile85(): iterable
    {
        yield 'class' => [
            "<?php\n#[\\Deprecated('old')]\nclass OldC {}\n",
            'Cannot apply #[\\Deprecated] to class OldC',
        ];
        yield 'interface' => [
            "<?php\n#[\\Deprecated('old')]\ninterface I {}\n",
            'Cannot apply #[\\Deprecated] to interface I',
        ];
        yield 'enum' => [
            "<?php\n#[\\Deprecated('old')]\nenum E {}\n",
            'Cannot apply #[\\Deprecated] to enum E',
        ];
    }

    /** @covers issue #28892 — trait path remains green under PROFILE=8.5 */
    public function testDeprecatedTraitUseEmitsNoticeUnderProfile85(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            if (!\PHPCompiler\CompilerVersion::supportsDeprecatedTraitAttribute()) {
                $this->markTestSkipped('requires PROFILE≥8.5 deprecated trait attribute');
            }
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
ini_set('error_reporting', '32767');
ini_set('display_errors', '0');
#[\Deprecated('old trait')]
trait T {}
class C { use T; }
$last = error_get_last();
echo $last['message'] ?? 'none';
PHP;
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'deprecated_trait_use_85.php'));
            $this->assertSame(
                'Trait T used by C is deprecated, old trait',
                ob_get_clean()
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testFunctionCallRecordsDeprecation(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
ini_set('error_reporting', '32767');
ini_set('display_errors', '0');
#[\Deprecated(message: "old")]
function f() {}
f();
$last = error_get_last();
echo $last['message'] ?? 'none';
PHP;
            ob_start();
            $runtime->run($runtime->parseAndCompile($code, 'deprecated_fn.php'));
            $this->assertSame('Function f() is deprecated, old', ob_get_clean());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
