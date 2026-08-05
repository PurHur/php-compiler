<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\SourcePreprocessor\PropertyHooks;
use PHPUnit\Framework\TestCase;

/** CompileFatal stderr channel matches Zend Parse vs Fatal (#18085, #18019). */
final class CompileFatalDiagnosticTest extends TestCase
{
    public function testSyntaxParseErrorUsesParseErrorPrefix(): void
    {
        $line = CompileFatal::formatZendStderrLine(
            PropertyHooks::REFERENCE_PROFILE_UNEXPECTED_BRACE,
            'example.php',
            3
        );
        $this->assertSame(
            'PHP Parse error:  syntax error, unexpected token "{", expecting "," or ";" in example.php on line 3'."\n",
            $line
        );
    }

    public function testCompileFatalUsesFatalErrorPrefix(): void
    {
        $line = CompileFatal::formatZendStderrLine(
            PropertyHooks::virtualHookedDefaultCompileError('C', 'x'),
            'example.php',
            4
        );
        $this->assertStringStartsWith('PHP Fatal error:  Cannot specify default value', $line);
    }

    public function testZendStderrLineOnCompileFatalInstance(): void
    {
        $fatal = new CompileFatal(
            'hooks.php',
            2,
            PropertyHooks::REFERENCE_PROFILE_UNEXPECTED_ARROW
        );
        $this->assertStringStartsWith('PHP Parse error:  syntax error, unexpected token "=>"', $fatal->zendStderrLine());
    }

    public function testVmEmitsParseErrorForReferenceProfilePropertyHooks(): void
    {
        if (CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('property hooks enabled on PHP 8.4 forward profile');
        }
        $repro = dirname(__DIR__).'/repro/maintainer_gap_property_hooks_reference_profile_parse.php';
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/bin/vm.php').' '
            .escapeshellarg($repro).' 2>&1';
        $output = shell_exec($cmd) ?? '';
        $firstLine = strtok($output, "\n") ?: '';
        $this->assertStringStartsWith(
            'PHP Parse error:  syntax error, unexpected token "{", expecting "," or ";"',
            $firstLine
        );
        $this->assertStringNotContainsString('Fatal error:', $firstLine);
    }
}
