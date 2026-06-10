<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmStripWhitespace;
use PHPUnit\Framework\TestCase;

/** Issue #7906: VmStripWhitespace must not delegate to host \\php_strip_whitespace(). */
final class VmStripWhitespaceRuntimeShrinkTest extends TestCase
{
    public function testVmStripWhitespaceDoesNotReferenceHostDelegation(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStripWhitespace.php');
        $this->assertStringContainsString('LanguageScanner::tokenize', $source);
        $this->assertStringContainsString('VmFs::fileGetContents', $source);
        $this->assertStringNotContainsString('function_exists(\'php_strip_whitespace\')', $source);
        $this->assertStringNotContainsString('\\php_strip_whitespace(', $source);
        $this->assertStringNotContainsString('\\file_get_contents(', $source);
    }

    public function testStripSourceRemovesComments(): void
    {
        $code = "<?php\n// comment\n\$x = 1;\n";
        $stripped = VmStripWhitespace::stripSource($code);
        $this->assertStringNotContainsString('comment', $stripped);
        $this->assertStringContainsString('$x = 1;', $stripped);
    }

    public function testStripFileReturnsEmptyForMissingPath(): void
    {
        $this->assertSame('', VmStripWhitespace::stripFile('/nonexistent/php-compiler-strip-test.php'));
    }
}
