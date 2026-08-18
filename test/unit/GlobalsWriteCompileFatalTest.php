<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #32229 — bare $GLOBALS writes are a Zend compile-time fatal */
final class GlobalsWriteCompileFatalTest extends TestCase
{
    private const MESSAGE = '$GLOBALS can only be modified using the $GLOBALS[$name] = $value syntax';

    /**
     * @dataProvider illegalGlobalsWriteProvider
     */
    public function testBareGlobalsWriteFailsAtCompileTime(string $code): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(self::MESSAGE);
        $runtime->parseAndCompile($code, 'globals_write.php');
    }

    /** @return iterable<string, array{string}> */
    public static function illegalGlobalsWriteProvider(): iterable
    {
        yield 'assign' => ['<?php $GLOBALS = []; echo "accepted\n";'];
        yield 'plus-assign' => ['<?php $GLOBALS += [\'x\' => 1]; echo "accepted\n";'];
        yield 'unset' => ['<?php unset($GLOBALS); echo "accepted\n";'];
        yield 'assign-ref target' => ['<?php $a = []; $GLOBALS =& $a; echo "accepted\n";'];
        yield 'pre-inc' => ['<?php ++$GLOBALS; echo "accepted\n";'];
    }

    public function testGlobalsDimAssignStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php $GLOBALS[\'x\'] = 1; echo $GLOBALS[\'x\'];',
            'globals_dim_ok.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('1', ob_get_clean());
    }

    public function testGlobalsDimPlusAssignStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php $GLOBALS[\'n\'] = 1; $GLOBALS[\'n\'] += 2; echo $GLOBALS[\'n\'];',
            'globals_dim_plus_ok.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('3', ob_get_clean());
    }

    public function testGlobalsDimUnsetStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php $GLOBALS[\'x\'] = 1; unset($GLOBALS[\'x\']); echo isset($GLOBALS[\'x\']) ? \'set\' : \'cleared\';',
            'globals_dim_unset_ok.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('cleared', ob_get_clean());
    }

    public function testGlobalsReferenceAcquisitionStillRejected(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot acquire reference to $GLOBALS');
        $runtime->parseAndCompile('<?php $a = &$GLOBALS;', 'globals_acquire_ref.php');
    }

    public function testGlobalsReadStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php $copy = $GLOBALS; echo is_array($copy) ? \'ok\' : \'no\';',
            'globals_read_ok.php'
        );
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('ok', ob_get_clean());
    }
}
