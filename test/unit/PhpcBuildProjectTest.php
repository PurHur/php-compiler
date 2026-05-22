<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Cli\PhpcBuild;
use PHPUnit\Framework\TestCase;

/**
 * phpc build --project actionable errors when user-class AOT is blocked (#643).
 */
final class PhpcBuildProjectTest extends TestCase
{
    public function testDetectsUnsupportedObjectTypeMessage(): void
    {
        $this->assertTrue(
            PhpcBuild::isUserClassAotBlocked('LogicException: Unsupported native type __object__')
        );
    }

    public function testDetectsLlvmVerifyInUserMethod(): void
    {
        $stderr = <<<'ERR'
Basic Block in function 'router::renderhome' does not have terminator!
Function return type does not match operand type of return inst!
ERR;
        $this->assertTrue(PhpcBuild::isUserClassAotBlocked($stderr));
    }

    public function testTrailerContainsIssue568AndGuidance(): void
    {
        $trailer = PhpcBuild::formatUserClassTrailer();
        $this->assertStringContainsString('#568', $trailer);
        $this->assertStringContainsString('user-defined classes', $trailer);
        $this->assertStringContainsString('phpc lint', $trailer);
        $this->assertStringContainsString('phpc serve', $trailer);
        $this->assertStringContainsString('miniwebapp-gates', $trailer);
    }

    public function testMiniWebAppBuildPrintsTrailerWhenCompileFails(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $phpc = $repoRoot.'/phpc';
        if (!is_file($phpc)) {
            $this->markTestSkipped('phpc wrapper missing');
        }
        if (!LlvmToolchain::isReady($repoRoot)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repoRoot);
        $proc = proc_open(
            [$phpc, 'build', '--project', $repoRoot.'/examples/003-MiniWebApp'],
            $descriptorSpec,
            $pipes,
            $repoRoot,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertNotSame(0, $exit);
        $stderr = false !== $stderr ? $stderr : '';
        $this->assertStringContainsString('#568', $stderr);
        $this->assertStringContainsString('user-defined classes', $stderr);
    }
}
