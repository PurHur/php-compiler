<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * php-src-strict guards: non-php-src builtins must not register (#13580, #13581).
 */
final class StdlibParityBuiltinGuardTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testArrayIsAssocSourceRemovedFromStdlib(): void
    {
        $module = (string) file_get_contents($this->repoRoot.'/ext/standard/Module.php');
        $this->assertStringNotContainsString('new array_is_assoc()', $module);
        $this->assertFileDoesNotExist($this->repoRoot.'/ext/standard/array_is_assoc.php');
    }

    public function testVmFunctionExistsArrayIsAssocFalse(): void
    {
        $code = <<<'PHP'
<?php
var_export(function_exists('array_is_assoc'));
PHP;

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'array_is_assoc_exists.php');
        ob_start();
        $runtime->run($block);
        $output = (string) ob_get_clean();

        $this->assertSame('false', trim($output));
    }

    public function testStrPaddedSourceRemovedFromStdlib(): void
    {
        $module = (string) file_get_contents($this->repoRoot.'/ext/standard/Module.php');
        $this->assertStringNotContainsString('new str_padded()', $module);
        $this->assertFileDoesNotExist($this->repoRoot.'/ext/standard/str_padded.php');
    }

    public function testVmFunctionExistsStrPaddedFalse(): void
    {
        $code = <<<'PHP'
<?php
var_export(function_exists('str_padded'));
PHP;

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'str_padded_exists.php');
        ob_start();
        $runtime->run($block);
        $output = (string) ob_get_clean();

        $this->assertSame('false', trim($output));
    }
}
