<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\ThrowableManifest;
use PHPCompiler\Runtime;
use PHPCompiler\VM\BuiltinClasses;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ExceptionSupport;
use PHPUnit\Framework\TestCase;

/** FiberStackOverflow VM registration and stack guard (#7267, #26741). */
final class FiberStackOverflowTest extends TestCase
{
    public function testManifestRegistersFiberStackOverflowUnderError(): void
    {
        $this->assertSame('Error', ThrowableManifest::parentName('FiberStackOverflow'));
        $this->assertSame(
            ThrowableManifest::LC_FIBER_STACK_OVERFLOW,
            ExceptionSupport::CLASS_FIBER_STACK_OVERFLOW
        );
    }

    public function testWithheldOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::advertisesFiberStackOverflowClass());
        $this->assertFalse(ThrowableManifest::isAdvertised('FiberStackOverflow'));

        $ctx = new Context(new Runtime());
        BuiltinClasses::register($ctx);
        $this->assertArrayNotHasKey(ThrowableManifest::LC_FIBER_STACK_OVERFLOW, $ctx->classes);

        [$stdout, $exit] = $this->runVmCli(
            '<?php
echo class_exists("FiberStackOverflow", false) ? "1" : "0";
'
        );
        $this->assertSame(0, $exit);
        $this->assertSame('0', $stdout);
    }

    public function testVmRegistersFiberStackOverflowClassOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::advertisesFiberStackOverflowClass());
            $this->assertTrue(ThrowableManifest::isAdvertised('FiberStackOverflow'));

            $ctx = new Context(new Runtime());
            BuiltinClasses::register($ctx);

            $this->assertArrayHasKey(ThrowableManifest::LC_FIBER_STACK_OVERFLOW, $ctx->classes);
            $entry = $ctx->classes[ThrowableManifest::LC_FIBER_STACK_OVERFLOW];
            $this->assertSame('FiberStackOverflow', $entry->name);
            $this->assertSame(ThrowableManifest::LC_ERROR, $entry->parentLc);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testFiberStackOverflowClassVisibleOnVmForwardProfile(): void
    {
        [$stdout, $exit] = $this->runVmCli(
            '<?php
echo class_exists("FiberStackOverflow", false) ? "1" : "0";
echo is_subclass_of("FiberStackOverflow", "Error") ? "1" : "0";
',
            ['PHP_COMPILER_PROFILE' => '8.4']
        );
        $this->assertSame(0, $exit);
        $this->assertSame('11', $stdout);
    }

    public function testInfiniteFiberRecursionThrowsFiberStackOverflow(): void
    {
        try {
            [$stdout, $exit] = $this->runVmCli(
                '<?php
function blow(): void { blow(); }
$f = new Fiber(function (): void { blow(); });
try {
    $f->start();
    echo "none";
} catch (FiberStackOverflow $e) {
    echo "FiberStackOverflow";
}
',
                ['PHP_COMPILER_PROFILE' => '8.4', 'PHP_COMPILER_FIBER_MAX_STACK_FRAMES' => '64']
            );
            $this->assertSame(0, $exit);
            $this->assertSame('FiberStackOverflow', $stdout);
        } finally {
            putenv('PHP_COMPILER_FIBER_MAX_STACK_FRAMES');
        }
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{0: string, 1: int}
     */
    private function runVmCli(string $code, array $env = []): array
    {
        $bin = realpath(__DIR__.'/../../bin/vm.php');
        $this->assertNotFalse($bin);
        $php = getenv('PHP_COMPILER_PHP') ?: PHP_BINARY;
        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $procEnv = null;
        if ([] !== $env) {
            $procEnv = getenv();
            foreach ($env as $k => $v) {
                $procEnv[$k] = $v;
            }
        }
        $proc = proc_open([$php, $bin], $descriptor, $pipes, dirname(__DIR__, 2), $procEnv);
        $this->assertIsResource($proc);
        fwrite($pipes[0], $code);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return [(string) $stdout, (int) $exit];
    }
}
