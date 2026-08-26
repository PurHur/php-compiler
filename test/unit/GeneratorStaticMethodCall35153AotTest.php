<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT: static method generators via STATICCALL_INIT (#35153).
 *
 * php-src: Zend/zend_generators.c
 */
final class GeneratorStaticMethodCall35153AotTest extends TestCase
{
    public function testInitJitStaticCallWiresGeneratorResumeCallee(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT.php');
        $marker = strpos($source, 'Class::g() (#35153');
        $this->assertNotFalse($marker);
        $chunk = substr($source, $marker, 1800);
        $this->assertStringContainsString('generatorResumeCallee', $chunk);
        $this->assertStringContainsString('creatorResumeName', $chunk);
        $this->assertStringContainsString('parentClassLc', $chunk);
        $begin = strpos($source, 'function initJitStaticCall');
        $this->assertNotFalse($begin);
        $this->assertGreaterThan($begin, $marker);
    }

    public function testAotFixtureAndReproExist(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileExists($root.'/test/fixtures/aot/cases/generator_static_method.phpt');
        $this->assertFileExists($root.'/test/repro/static_method_generator_35153.php');
        $this->assertFileExists($root.'/test/repro/static_method_generator_inherit_35153.php');
        $fixture = (string) file_get_contents($root.'/test/fixtures/aot/cases/generator_static_method.phpt');
        $this->assertStringContainsString('#35153', $fixture);
        $this->assertStringContainsString('--EXPECT--', $fixture);
        $this->assertStringContainsString("12\n", $fixture."\n");
        $this->assertStringContainsString("34\n", $fixture."\n");
        $this->assertStringContainsString("56\n", $fixture."\n");
    }
}
