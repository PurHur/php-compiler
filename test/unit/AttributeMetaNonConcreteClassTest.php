<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #26241 — PHP 8.5 #[\Attribute] on abstract/interface/trait/enum */
final class AttributeMetaNonConcreteClassTest extends TestCase
{
    private ?string $prevProfile = null;

    protected function setUp(): void
    {
        $raw = getenv('PHP_COMPILER_PROFILE');
        $this->prevProfile = false === $raw ? null : $raw;
    }

    protected function tearDown(): void
    {
        if (null === $this->prevProfile) {
            putenv('PHP_COMPILER_PROFILE');
            unset($_ENV['PHP_COMPILER_PROFILE'], $_SERVER['PHP_COMPILER_PROFILE']);
        } else {
            putenv('PHP_COMPILER_PROFILE='.$this->prevProfile);
            $_ENV['PHP_COMPILER_PROFILE'] = $this->prevProfile;
            $_SERVER['PHP_COMPILER_PROFILE'] = $this->prevProfile;
        }
    }

    private function setProfile(string $profile): void
    {
        putenv('PHP_COMPILER_PROFILE='.$profile);
        $_ENV['PHP_COMPILER_PROFILE'] = $profile;
        $_SERVER['PHP_COMPILER_PROFILE'] = $profile;
    }

    public function testRejectsGateFalseOnProfile84(): void
    {
        $this->setProfile('8.4');
        $this->assertFalse(CompilerVersion::rejectsAttributeOnNonConcreteClassLike());
    }

    public function testRejectsGateTrueOnProfile85(): void
    {
        $this->setProfile('8.5');
        $this->assertTrue(CompilerVersion::rejectsAttributeOnNonConcreteClassLike());
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    public static function invalidTargets85(): array
    {
        return [
            ['abstract class', '#[\Attribute] abstract class A {}', 'Cannot apply #[\\Attribute] to abstract class A'],
            ['interface', '#[\Attribute] interface A {}', 'Cannot apply #[\\Attribute] to interface A'],
            ['trait', '#[\Attribute] trait A {}', 'Cannot apply #[\\Attribute] to trait A'],
            ['enum', '#[\Attribute] enum A { case X; }', 'Cannot apply #[\\Attribute] to enum A'],
        ];
    }

    /**
     * @dataProvider invalidTargets85
     */
    public function testCompileFatalOnProfile85(string $kind, string $decl, string $message): void
    {
        $this->setProfile('8.5');
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage($message);
        $runtime->parseAndCompile("<?php\n".$decl."\n", 'attr_meta_'.$kind.'.php');
    }

    /**
     * @dataProvider invalidTargets85
     */
    public function testAllowedOnProfile84(string $kind, string $decl, string $message): void
    {
        $this->setProfile('8.4');
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile("<?php\n".$decl."\necho \"ok\\n\";\n", 'attr_meta84_'.$kind.'.php');
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testDelayedTargetValidationDefersCompileAndNewInstanceErrors(): void
    {
        $this->setProfile('8.5');
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[\DelayedTargetValidation]
#[\Attribute]
abstract class DelayedAttr {}
echo "compiled\n";
try {
    (new ReflectionClass(DelayedAttr::class))->getAttributes(Attribute::class)[0]->newInstance();
    echo "newInstance=ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attr_meta_delayed.php'));
        $this->assertSame(
            "compiled\nError: Cannot apply #[\\Attribute] to abstract class DelayedAttr\n",
            ob_get_clean()
        );
    }

    /** @covers issue #26329 — DelayedTargetValidation for Deprecated / Override / SensitiveParameter */
    public function testDelayedTargetValidationDefersDeprecatedOverrideSensitive(): void
    {
        $this->setProfile('8.5');
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[\DelayedTargetValidation]
#[\Deprecated('x')]
class X {}
echo "dep=ok\n";
try {
    (new ReflectionClass(X::class))->getAttributes(Deprecated::class)[0]->newInstance();
} catch (Throwable $e) {
    echo 'dep=', get_class($e), ':', $e->getMessage(), "\n";
}
class Holder {
    #[\DelayedTargetValidation]
    #[\Override]
    public const NAME = 'c';
}
echo "ovr=ok\n";
try {
    (new ReflectionClassConstant(Holder::class, 'NAME'))->getAttributes(Override::class)[0]->newInstance();
} catch (Throwable $e) {
    echo 'ovr=', get_class($e), ':', $e->getMessage(), "\n";
}
#[\DelayedTargetValidation]
#[\SensitiveParameter]
class Z {}
echo "sens=ok\n";
try {
    (new ReflectionClass(Z::class))->getAttributes(SensitiveParameter::class)[0]->newInstance();
} catch (Throwable $e) {
    echo 'sens=', get_class($e), ':', $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attr_dtv_internal.php'));
        $this->assertSame(
            "dep=ok\n"
            ."dep=Error:Cannot apply #[\\Deprecated] to class X\n"
            ."ovr=ok\n"
            ."ovr=Error:Attribute \"Override\" cannot target class constant (allowed targets: method, property)\n"
            ."sens=ok\n"
            ."sens=Error:Attribute \"SensitiveParameter\" cannot target class (allowed targets: parameter)\n",
            ob_get_clean()
        );
    }

    /** @covers issue #26329 — #[\Override] functional validation is not delayed */
    public function testDelayedTargetValidationDoesNotSuppressOverrideFunctionalCheck(): void
    {
        $this->setProfile('8.5');
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('has #[\Override] attribute, but no matching parent method exists');
        $runtime->parseAndCompile(<<<'PHP'
<?php
class P {}
class C extends P {
    #[\DelayedTargetValidation]
    #[\Override]
    public function missing(): void {}
}
PHP
            , 'attr_dtv_override_func.php');
    }

    public function testConcreteClassStillAllowedOnProfile85(): void
    {
        $this->setProfile('8.5');
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[\Attribute]
class Marker {}
echo class_exists(Marker::class) ? "ok\n" : "no\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attr_meta_concrete85.php'));
        $this->assertSame("ok\n", ob_get_clean());
    }
}
