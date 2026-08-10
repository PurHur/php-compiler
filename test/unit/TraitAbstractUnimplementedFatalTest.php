<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\VM\ScriptExit;
use PHPUnit\Framework\TestCase;

/**
 * @covers issue #25912
 *
 * php-src: Zend/zend_inheritance.c — zend_verify_abstract_class at class DECLARE.
 */
final class TraitAbstractUnimplementedFatalTest extends TestCase
{
    public function testPrecedingEchoRunsThenCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait TAbs {
    abstract public function f();
}
echo "before\n";
class C {
    use TAbs;
}
echo "accepted\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'trait_abstract_unimpl.php');
        $this->assertNotNull($block);

        ob_start();
        try {
            // bubbleUncaught=false — ScriptExit like bin/vm.php CLI (#25912).
            $runtime->run($block, false);
            ob_end_clean();
            $this->fail('Expected ScriptExit for unimplemented trait abstract method');
        } catch (\CompileError $e) {
            $out = ob_get_clean();
            $this->assertStringContainsString('before', (string) $out);
            $this->assertStringNotContainsString('accepted', (string) $out);
            $this->assertStringContainsString('Class C contains 1 abstract method', $e->getMessage());
            $this->assertStringContainsString('(C::f)', $e->getMessage());
        } catch (ScriptExit $e) {
            $out = ob_get_clean();
            $this->assertSame(255, $e->status);
            $this->assertStringContainsString('before', (string) $out);
            $this->assertStringNotContainsString('accepted', (string) $out);
            // Fatal body goes to stderr as "PHP Fatal error:" (display_errors=0); CLI repro covers that.
        }
    }

    public function testImplementedTraitAbstractCompilesAndRuns(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait TAbs {
    abstract public function f(): int;
}
class C {
    use TAbs;
    public function f(): int { return 7; }
}
echo C::class, ":", (new C())->f(), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'trait_abstract_ok.php'));
        $this->assertSame("C:7\n", ob_get_clean());
    }

    /**
     * Abstract class may import abstract trait methods; concrete child must run (#29552).
     * error_reporting before the decls is the regression trigger (hoisted child vs source-order parent).
     */
    public function testAbstractClassImportsAbstractTraitMethodAndChildRuns(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
error_reporting(E_ALL);
trait TAbs {
    abstract public function f();
}
abstract class A {
    use TAbs;
}
class B extends A {
    public function f() { return 1; }
}
echo (new B)->f(), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'trait_abstract_class_ok.php'));
        $this->assertSame("1\n", ob_get_clean());
    }
}
