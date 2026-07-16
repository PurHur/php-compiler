<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Ast\NewDereferenceableDesugar;
use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** PHP 8.4 dereferencable `new` without outer parentheses (#6974, #19684). */
final class NewDereferenceableDesugarTest extends TestCase
{
    private ?string $previousProfile = null;

    protected function setUp(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        $this->previousProfile = false === $prev ? null : $prev;
        putenv('PHP_COMPILER_PROFILE=8.4');
        if (!CompilerVersion::supportsDereferencableNewWithoutOuterParens()) {
            $this->markTestSkipped('dereferencable new forward profile unavailable');
        }
    }

    protected function tearDown(): void
    {
        if (null === $this->previousProfile) {
            putenv('PHP_COMPILER_PROFILE');
        } else {
            putenv('PHP_COMPILER_PROFILE='.$this->previousProfile);
        }
    }

    public function testDesugarWrapsMethodChain(): void
    {
        $code = '<?php echo new Greeter()->greet();';
        $this->assertSame(
            '<?php echo (new Greeter())->greet();',
            NewDereferenceableDesugar::desugar($code)
        );
    }

    public function testDesugarWrapsPropertyFetch(): void
    {
        $code = '<?php echo new Box()->value;';
        $this->assertSame(
            '<?php echo (new Box())->value;',
            NewDereferenceableDesugar::desugar($code)
        );
    }

    public function testDesugarWrapsStaticCall(): void
    {
        $code = '<?php echo new Worker()::run();';
        $this->assertSame(
            '<?php echo (new Worker())::run();',
            NewDereferenceableDesugar::desugar($code)
        );
    }

    public function testDesugarWrapsDynamicClassName(): void
    {
        $code = '<?php echo new $class()->m();';
        $this->assertSame(
            '<?php echo (new $class())->m();',
            NewDereferenceableDesugar::desugar($code)
        );
    }

    public function testDesugarWrapsAnonymousClass(): void
    {
        $code = '<?php echo new class () { public function m(): int { return 1; } }->m();';
        $this->assertSame(
            '<?php echo (new class () { public function m(): int { return 1; } })->m();',
            NewDereferenceableDesugar::desugar($code)
        );
    }

    public function testDesugarSkipsAlreadyParenthesized(): void
    {
        $code = '<?php echo (new Greeter())->greet();';
        $this->assertSame($code, NewDereferenceableDesugar::desugar($code));
    }

    public function testDesugarSkipsBareNewWithoutCtorParens(): void
    {
        $code = '<?php $x = new stdClass;';
        $this->assertSame($code, NewDereferenceableDesugar::desugar($code));
    }

    public function testVmNewChainWithoutParens(): void
    {
        $code = <<<'PHP'
<?php
class Greeter
{
    public function greet(): string
    {
        return 'hello';
    }
}
echo new Greeter()->greet(), "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'new_chain.php');
        $this->assertNotNull($block);
        ob_start();
        $rt->run($block);
        $this->assertSame("hello\n", ob_get_clean());
    }
}
