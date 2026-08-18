<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #32204 — catch (Exception $this) is a Zend compile-time fatal */
final class CatchThisCompileFatalTest extends TestCase
{
    /**
     * @dataProvider illegalCatchThisProvider
     */
    public function testCatchThisFailsAtCompileTime(string $code): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot re-assign $this');
        $runtime->parseAndCompile($code, 'catch_this.php');
    }

    /** @return iterable<string, array{string}> */
    public static function illegalCatchThisProvider(): iterable
    {
        yield 'method scope' => [
            '<?php class C { public function m() { try { throw new Exception("x"); } catch (Exception $this) { echo "accepted\n"; } } } (new C())->m();',
        ];
        yield 'file scope' => [
            '<?php try { throw new Exception("x"); } catch (Exception $this) { echo "accepted\n"; }',
        ];
        yield 'union catch' => [
            '<?php try { throw new Exception("x"); } catch (Exception|Error $this) { echo "accepted\n"; }',
        ];
        yield 'function scope' => [
            '<?php function foo() { try { throw new Exception("x"); } catch (Exception $this) { echo "accepted\n"; } } foo();',
        ];
        yield 'static method' => [
            '<?php class C { public static function m() { try { throw new Exception("x"); } catch (Exception $this) { echo "accepted\n"; } } } C::m();',
        ];
    }

    public function testLegalCatchStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php try { throw new Exception("ok"); } catch (Exception $e) { echo $e->getMessage(); }',
            'catch_ok.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('ok', ob_get_clean());
    }

    public function testCatchWithoutVariableStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php try { throw new Exception("x"); } catch (Exception) { echo "caught"; }',
            'catch_unbound.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('caught', ob_get_clean());
    }

    public function testMethodThisStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php class C { public int $n = 9; public function m() { echo $this->n; } } (new C())->m();',
            'method_this_ok.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('9', ob_get_clean());
    }
}
