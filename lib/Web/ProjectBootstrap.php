<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

use PHPCompiler\Runtime;

/**
 * Load phpc.json includes[] and PSR-4 autoload before VM compile/run (issue #155).
 */
final class ProjectBootstrap
{
    public static function prepare(Runtime $runtime, ?string $projectDir, ?array $manifest): void
    {
        if (null === $projectDir || null === $manifest) {
            return;
        }

        foreach (ProjectManifest::resolveIncludePaths($projectDir, $manifest) as $path) {
            $runtime->vm->executeCompileUnit($path);
        }

        ProjectAutoload::registerVmAutoload(
            $runtime,
            ProjectAutoload::parsePsr4Map($projectDir, $manifest)
        );
    }

    /**
     * Resolve project context from an entry script path (public/index.php, etc.).
     *
     * @return array{0: string|null, 1: array<string, mixed>|null}
     */
    public static function resolveFromScript(string $scriptPath): array
    {
        $projectDir = ProjectManifest::resolveProjectDir(dirname($scriptPath));
        if (null === $projectDir) {
            return [null, null];
        }

        return [$projectDir, ProjectManifest::loadManifest($projectDir)];
    }
}
