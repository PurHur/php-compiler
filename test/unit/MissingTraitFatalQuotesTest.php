<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\VM\TraitCompositionConflictMessage;
use PHPUnit\Framework\TestCase;

/**
 * Missing {@code use Trait;} must Fatal like Zend (#30012, zend_compile.c).
 */
final class MissingTraitFatalQuotesTest extends TestCase
{
    public function testNotFoundMessageHelper(): void
    {
        $this->assertSame(
            'Trait "MissingTrait" not found',
            TraitCompositionConflictMessage::notFound('MissingTrait')
        );
    }

    public function testVmRuntimeFatalIncludesQuotesAndFraming(): void
    {
        $runtime = new Runtime();
        $code = '<?php class A { use MissingTrait; }';
        $block = $runtime->parseAndCompile($code, '/tmp/missing_trait_30012.php');
        $this->assertNotNull($block);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'PHP Fatal error:  Trait "MissingTrait" not found in /tmp/missing_trait_30012.php on line 1'
        );
        $runtime->run($block, false);
    }

    public function testVmCliDriverMatchesZendShape(): void
    {
        $code = "<?php\nclass A { use MissingTrait; }\n";
        $path = '/tmp/phpc_missing_trait_30012.php';
        file_put_contents($path, $code);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/bin/vm.php')
            .' '.escapeshellarg($path).' 2>&1';
        $output = shell_exec($cmd) ?? '';
        @unlink($path);

        $this->assertStringContainsString('PHP Fatal error:', $output);
        $this->assertStringContainsString('Trait "MissingTrait" not found', $output);
        $this->assertStringNotContainsString('Trait MissingTrait not found', $output);
        $this->assertMatchesRegularExpression('/on line \d+/', $output);
    }
}
