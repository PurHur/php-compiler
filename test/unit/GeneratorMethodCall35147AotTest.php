<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT: instance method generators via METHODCALL_INIT (#35147).
 *
 * php-src: Zend/zend_generators.c
 */
final class GeneratorMethodCall35147AotTest extends TestCase
{
    public function testInitJitMethodCallWiresGeneratorResumeCallee(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT.php');
        $marker = strpos($source, '#35147 / Zend zend_generators.c');
        $this->assertNotFalse($marker);
        $chunk = substr($source, $marker, 1800);
        $this->assertStringContainsString('generatorResumeCallee', $chunk);
        $this->assertStringContainsString('creatorResumeName', $chunk);
        $this->assertStringContainsString('parentClassLc', $chunk);
        $begin = strpos($source, 'function initJitMethodCall');
        $this->assertNotFalse($begin);
        $this->assertGreaterThan($begin, $marker);
    }

    public function testAotFixtureAndReproExist(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileExists($root.'/test/fixtures/aot/cases/generator_method_call.phpt');
        $this->assertFileExists($root.'/test/repro/issue_35147_method_generator.php');
        $this->assertFileExists($root.'/test/repro/issue_35147_method_generator_inherit.php');
        $fixture = (string) file_get_contents($root.'/test/fixtures/aot/cases/generator_method_call.phpt');
        $this->assertStringContainsString('#35147', $fixture);
        $this->assertStringContainsString('--EXPECT--', $fixture);
        $this->assertStringContainsString("12\n", $fixture."\n");
        $this->assertStringContainsString("34\n", $fixture."\n");
    }
}
