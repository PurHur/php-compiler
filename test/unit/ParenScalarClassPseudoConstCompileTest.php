<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Parenthesized numeric `(1)::class` / `(1.5)::class` — Zend Illegal class name (#29625).
 *
 * @covers \PHPCompiler\PHPTypes\CompilerTypeReconstructor
 * @covers \PHPCompiler\Compiler::rejectIllegalLiteralClassNameOperand
 */
final class ParenScalarClassPseudoConstCompileTest extends TestCase
{
    private ?string $prevProfile = null;

    protected function setUp(): void
    {
        $this->prevProfile = getenv('PHP_COMPILER_PROFILE') ?: null;
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }

    protected function tearDown(): void
    {
        if (null === $this->prevProfile) {
            putenv('PHP_COMPILER_PROFILE');
            unset($_ENV['PHP_COMPILER_PROFILE']);
        } else {
            putenv('PHP_COMPILER_PROFILE='.$this->prevProfile);
            $_ENV['PHP_COMPILER_PROFILE'] = $this->prevProfile;
        }
    }

    /**
     * @dataProvider illegalLiteralClassProvider
     */
    public function testParenNumericClassConstIsIllegalClassName(string $code): void
    {
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile($code, 'paren_scalar_class.php');
            self::fail('Expected CompileFatal for illegal literal class name');
        } catch (CompileFatal $e) {
            self::assertSame('Illegal class name', $e->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function illegalLiteralClassProvider(): iterable
    {
        yield 'int ::class' => ['<?php echo (1)::class;'];
        yield 'float ::class' => ['<?php echo (1.5)::class;'];
        yield 'int ::FOO' => ['<?php echo (1)::FOO;'];
        yield 'zero ::class' => ['<?php echo (0)::class;'];
    }

    public function testParenTrueClassStillCannotUseOnTrue(): void
    {
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile('<?php echo (true)::class;', 'paren_true_class.php');
            self::fail('Expected CompileFatal for (true)::class');
        } catch (CompileFatal $e) {
            self::assertSame('Cannot use "::class" on true', $e->getMessage());
        }
    }

    public function testParenStringClassResolves(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile('<?php echo ("x")::class;', 'paren_string_class.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();
        self::assertSame('x', $output);
    }

    public function testVariableIntClassIsTypeErrorNotIllegalName(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php $i = 1; try { echo $i::class; } catch (Throwable $t) { echo get_class($t), ": ", $t->getMessage(); }',
            'var_int_class.php'
        );
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();
        self::assertStringContainsString('TypeError:', $output);
        self::assertStringContainsString('Cannot use "::class" on int', $output);
        self::assertStringNotContainsString('Illegal class name', $output);
    }
}
