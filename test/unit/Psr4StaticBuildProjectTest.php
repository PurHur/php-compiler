<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Cli\PhpcBuild;
use PHPCompiler\Web\ProjectManifest;
use PHPUnit\Framework\TestCase;

/**
 * phpc build --project uses ProjectGraph PSR-4 discovery (issue #154 phase 2).
 */
final class Psr4StaticBuildProjectTest extends TestCase
{
    public function testResolveGraphIncludePathsDiscoversGreeterWithoutManifestIncludes(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $project = $repoRoot.'/test/fixtures/aot/projects/psr4_static';
        if (!is_file($project.'/phpc.json')) {
            $this->markTestSkipped('psr4_static fixture missing');
        }

        $entry = ProjectManifest::resolveEntryPath($project);
        $this->assertNotNull($entry);

        $resolved = PhpcBuild::resolveGraphIncludePaths($project, $entry);
        $this->assertSame([], $resolved['errors'], implode("\n", $resolved['errors']));

        $joined = implode("\n", $resolved['includes']);
        $this->assertStringContainsString('src/Greeter.php', $joined);
        $this->assertStringNotContainsString('public/index.php', $joined);
    }

    public function testListUnitsCliIncludesGreeter(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $project = $repoRoot.'/test/fixtures/aot/projects/psr4_static';
        $phpc = $repoRoot.'/phpc';
        if (!is_file($phpc)) {
            $this->markTestSkipped('phpc wrapper missing');
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            [$phpc, 'build', '--project', $project, '--list-units'],
            $descriptorSpec,
            $pipes,
            $repoRoot
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $stderr = false !== $stderr ? $stderr : '';
        $this->assertSame(0, $exit, substr($stderr, 0, 500));
        $this->assertStringContainsString('src/Greeter.php', $stderr);
    }
}
