<?php

declare(strict_types=1);

namespace PHPCompiler\Cli;

use PHPCompiler\AOT\ProjectGraph;
use PHPCompiler\Lint\Issue;
use PHPCompiler\Lint\Linter;
use PHPCompiler\Lint\UnsupportedRegistry;
use PHPCompiler\Web\ProjectManifest;

/**
 * phpc build --project orchestration and actionable AOT failure hints (issue #643, #764, #684, #792).
 */
final class PhpcBuild
{
    private const ISSUE_ROADMAP = 'https://github.com/PurHur/php-compiler/issues/78';

    /** Exit when --probe runs and linked binary prints 0 stdout bytes (#792, #773). */
    public const EXIT_EMPTY_EXECUTE_PROBE = 2;

    /**
     * @param list<string> $php Host PHP argv prefix (binary + -d flags)
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    public static function runCompile(
        array $php,
        string $repoRoot,
        string $compileScript,
        string $cwd,
        array $compileArgv
    ): array {
        $cmd = array_merge($php, [$compileScript], $compileArgv);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $cwd);
        if (!is_resource($proc)) {
            return [
                'exit' => 1,
                'stdout' => '',
                'stderr' => 'Failed to start: '.implode(' ', $cmd)."\n",
            ];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return [
            'exit' => is_int($exit) ? $exit : 1,
            'stdout' => false !== $stdout ? $stdout : '',
            'stderr' => false !== $stderr ? $stderr : '',
        ];
    }

    /**
     * Grep-friendly compile-unit summary on stderr; no LLVM (issue #847).
     */
    public static function printListUnits(string $projectDir): int
    {
        $root = ProjectManifest::resolveProjectDir($projectDir);
        if (null === $root) {
            fwrite(STDERR, "phpc build --project --list-units: phpc.json not found in {$projectDir}\n");

            return 1;
        }

        $graph = ProjectGraph::resolve($projectDir);
        if ([] !== $graph['errors']) {
            foreach ($graph['errors'] as $message) {
                fwrite(STDERR, $message."\n");
            }

            return 1;
        }

        $entry = ProjectManifest::resolveEntryPath($root);
        $binary = ProjectManifest::resolveBinaryOutputPath($root);
        $units = ProjectGraph::formatFileList($projectDir, $graph['files']);

        if (null !== $entry) {
            fwrite(STDERR, 'entry: '.self::displayPath($root, $entry)."\n");
        }
        fwrite(STDERR, 'units: '.implode(', ', $units)."\n");
        if (null !== $binary) {
            fwrite(STDERR, 'binary: '.self::displayPath($root, $binary)."\n");
        }

        $linter = new Linter();
        foreach ($graph['files'] as $absolutePath) {
            if (!is_file($absolutePath)) {
                continue;
            }
            try {
                $issues = $linter->lintFile($absolutePath);
            } catch (\Throwable) {
                continue;
            }
            if ([] === $issues) {
                continue;
            }
            $kinds = UnsupportedRegistry::uniqueKinds($issues);
            fwrite(
                STDERR,
                'skipped: '.self::displayPath($root, $absolutePath).' (lint: '.implode(', ', $kinds).")\n"
            );
        }

        return 0;
    }

    /**
     * Print manifest link order (includes[] then entry) as absolute paths; no LLVM (issue #729).
     */
    public static function printIncludes(string $projectDir): int
    {
        $root = ProjectManifest::resolveProjectDir($projectDir);
        if (null === $root) {
            fwrite(STDERR, "phpc build --project --print-includes: phpc.json not found in {$projectDir}\n");

            return 1;
        }

        $paths = ProjectManifest::resolveCompileUnitPaths($projectDir);
        if (null === $paths) {
            fwrite(STDERR, "phpc build --project --print-includes: could not resolve entry from phpc.json\n");

            return 1;
        }

        foreach ($paths as $path) {
            if (!is_file($path)) {
                fwrite(STDERR, 'phpc build --project --print-includes: file not found: '.$path."\n");

                return 1;
            }
            $absolute = realpath($path);
            fwrite(STDOUT, (false !== $absolute ? $absolute : $path)."\n");
        }

        return 0;
    }

    public static function verboseEnabled(bool $cliFlag): bool
    {
        if ($cliFlag) {
            return true;
        }
        $env = getenv('PHPC_BUILD_VERBOSE');
        if (false === $env || '' === $env) {
            return false;
        }

        return in_array(strtolower($env), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Human-readable compile-unit graph on stderr before LLVM link (issue #684).
     */
    public static function emitVerboseProjectGraph(string $projectDir): void
    {
        $root = ProjectManifest::resolveProjectDir($projectDir);
        if (null === $root) {
            fwrite(STDERR, "phpc build --project --verbose: phpc.json not found in {$projectDir}\n");

            return;
        }

        $graph = ProjectGraph::resolve($projectDir);
        $displayRoot = ProjectGraph::formatFileList($projectDir, [$root])[0] ?? $root;
        $entry = ProjectManifest::resolveEntryPath($root);
        $binary = ProjectManifest::resolveBinaryOutputPath($root);
        $manifestIncludes = ProjectManifest::resolveIncludePaths($root);

        fwrite(STDERR, "=== phpc build --project (verbose) ===\n");
        fwrite(STDERR, "project: {$displayRoot}\n");
        fwrite(STDERR, "manifest: {$displayRoot}/phpc.json\n");
        if (null !== $entry) {
            fwrite(STDERR, 'entry: '.self::displayPath($root, $entry)."\n");
        }
        if (null !== $binary) {
            fwrite(STDERR, 'binary: '.self::displayPath($root, $binary)."\n");
        }
        fwrite(STDERR, "includes[]:\n");
        if ([] === $manifestIncludes) {
            fwrite(STDERR, "  (none)\n");
        } else {
            foreach ($manifestIncludes as $path) {
                fwrite(STDERR, '  '.self::displayPath($root, $path)."\n");
            }
        }

        if ([] !== $graph['errors']) {
            fwrite(STDERR, "graph errors:\n");
            foreach ($graph['errors'] as $message) {
                fwrite(STDERR, "  {$message}\n");
            }
            fwrite(STDERR, "===\n");

            return;
        }

        $units = ProjectGraph::formatFileList($projectDir, $graph['files']);
        fwrite(STDERR, 'compile units ('.count($units)."):\n");
        foreach ($units as $unit) {
            fwrite(STDERR, "  {$unit}\n");
        }

        $linter = new Linter();
        fwrite(STDERR, "lint:\n");
        $issuesByFile = [];
        foreach ($graph['files'] as $absolutePath) {
            if (!is_file($absolutePath)) {
                continue;
            }
            try {
                $fileIssues = $linter->lintFile($absolutePath);
            } catch (\Throwable) {
                $fileIssues = [];
            }
            $issuesByFile[$absolutePath] = $fileIssues;
            $rel = self::displayPath($root, $absolutePath);
            if ([] === $fileIssues) {
                fwrite(STDERR, "  {$rel}: pass\n");
                continue;
            }
            $kinds = UnsupportedRegistry::uniqueKinds($fileIssues);
            fwrite(STDERR, "  {$rel}: blocked (".implode(', ', $kinds).")\n");
            foreach ($fileIssues as $issue) {
                fwrite(STDERR, '    '.$issue->formatHuman()."\n");
            }
        }

        $classes = self::discoverUserClasses($graph['files'], $root);
        if ([] !== $classes) {
            fwrite(STDERR, "native link: user-defined classes may still fail AOT execute (see phpc lint)\n");
            foreach ($classes as $classInfo) {
                fwrite(STDERR, "  class {$classInfo['class']} ({$classInfo['file']})\n");
                foreach ($classInfo['methods'] as $method) {
                    fwrite(STDERR, "    method {$classInfo['class']}::{$method}\n");
                }
            }
        }

        $blockedKinds = self::collectBlockedKinds($issuesByFile);
        if ([] !== $blockedKinds) {
            fwrite(STDERR, 'blocked opcodes: '.implode(', ', $blockedKinds)."\n");
        }

        fwrite(STDERR, "===\n");
    }

    /**
     * True when compile/link stderr matches user-class AOT link/verify failures.
     */
    public static function isUserClassAotBlocked(string $stderr): bool
    {
        if ('' === $stderr) {
            return false;
        }
        $needles = [
            'Unsupported native type __object__',
            '__object__',
            'user-class',
            'user class',
            'router::',
            '::render',
            'Other class body types are not jittable',
            'JIT opcode',
            'LogicException',
            'does not have terminator',
            'Function return type does not match operand type of return inst',
            'LLVMAbstract\\Module->verify',
        ];
        foreach ($needles as $needle) {
            if (str_contains($stderr, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function formatUserClassTrailer(): string
    {
        return implode("\n", [
            '',
            '---',
            'phpc build --project: user-class native AOT may fail LLVM verify or empty execute.',
            'Regressions: MiniWebAppAotExecuteTest · MINIWEBAPP_AOT_EXECUTE_GATE',
            'Roadmap: '.self::ISSUE_ROADMAP,
            'Next steps:',
            '  ./phpc lint --all <project>',
            '  ./phpc serve 127.0.0.1:8080 <project>   # VM / dev server',
            '  make miniwebapp-gates                    # lint + serve gates (no AOT link)',
            'Use phpc build --verbose to print the compile-unit graph and full LLVM stderr.',
            '---',
            '',
        ]);
    }

    /**
     * Web manifest: public/ tree or multi-file includes[] (issue #792).
     */
    public static function isWebProjectForExecuteProbe(string $projectDir): bool
    {
        $manifest = ProjectManifest::loadManifest($projectDir);
        if (null === $manifest) {
            return false;
        }
        if (isset($manifest['public']) && is_string($manifest['public']) && '' !== $manifest['public']) {
            return true;
        }
        $includes = $manifest['includes'] ?? null;

        return is_array($includes) && count($includes) >= 2;
    }

    /**
     * Post-link trailer with byte-probe command when build succeeded (#792).
     */
    public static function formatWebProjectSuccessTrailer(string $projectDir, string $binaryPath): string
    {
        $root = ProjectManifest::resolveProjectDir($projectDir);
        if (null === $root) {
            return '';
        }
        $binaryRel = self::displayPath($root, $binaryPath);
        $sizeLabel = self::formatBinarySizeLabel($binaryPath);
        $projectLabel = basename($root);
        $probe = self::defaultExecuteProbeShellLine($root, $binaryRel);

        return implode("\n", [
            '',
            '---',
            "Linked {$binaryRel} ({$sizeLabel}). Quick execute probe:",
            "  cd {$projectLabel} && {$probe}",
            '  # or: phpc run --project . --cgi-env QUERY_STRING=route=home --cgi-env REQUEST_METHOD=GET  (#774)',
            'Empty stdout? Run MiniWebAppAotExecuteTest; not a link failure.',
            '---',
            '',
        ]);
    }

    public static function formatBinarySizeLabel(string $binaryPath): string
    {
        if (!is_file($binaryPath)) {
            return '0 B';
        }
        $bytes = filesize($binaryPath);
        if (false === $bytes) {
            return '0 B';
        }
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        return (string) round($bytes / 1024).' KB';
    }

    /**
     * @return array{exit: int, bytes: int, stdout: string}
     */
    public static function runExecuteByteProbe(string $projectDir, string $binaryPath): array
    {
        $root = ProjectManifest::resolveProjectDir($projectDir);
        if (null === $root || !is_executable($binaryPath)) {
            return ['exit' => 1, 'bytes' => 0, 'stdout' => ''];
        }

        $entry = ProjectManifest::resolveEntryPath($root);
        $publicDir = ProjectManifest::resolvePublicDir($root);
        $cwd = is_dir($publicDir) ? $publicDir : $root;

        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        foreach (self::defaultExecuteProbeCgiEnv($root, $entry) as $key => $value) {
            $env[$key] = $value;
        }
        if (null !== $entry) {
            $env['SCRIPT_FILENAME'] = $entry;
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open([$binaryPath], $descriptorSpec, $pipes, $cwd, $env);
        if (!is_resource($proc)) {
            return ['exit' => 1, 'bytes' => 0, 'stdout' => ''];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $stdout = false !== $stdout ? $stdout : '';
        if (false !== $stderr && '' !== $stderr) {
            fwrite(STDERR, $stderr);
        }

        return [
            'exit' => is_int($exit) ? $exit : 1,
            'bytes' => strlen($stdout),
            'stdout' => $stdout,
        ];
    }

    /**
     * @param array{exit: int, stdout: string, stderr: string} $result
     */
    public static function emitBuildOutput(
        array $result,
        bool $verbose,
        ?string $projectDir = null,
        ?string $binaryPath = null
    ): void {
        if ('' !== $result['stdout']) {
            fwrite(STDOUT, $result['stdout']);
            if (!str_ends_with($result['stdout'], "\n")) {
                fwrite(STDOUT, "\n");
            }
        }

        $userClassBlocked = 0 !== $result['exit'] && self::isUserClassAotBlocked($result['stderr']);
        $emitStderr = '' !== $result['stderr'] && ($verbose || !$userClassBlocked);
        if ($emitStderr) {
            fwrite(STDERR, $result['stderr']);
            if (!str_ends_with($result['stderr'], "\n")) {
                fwrite(STDERR, "\n");
            }
        }
        if ($userClassBlocked) {
            fwrite(STDERR, self::formatUserClassTrailer());
        } elseif (
            0 === $result['exit']
            && null !== $projectDir
            && null !== $binaryPath
            && self::isWebProjectForExecuteProbe($projectDir)
        ) {
            fwrite(STDERR, self::formatWebProjectSuccessTrailer($projectDir, $binaryPath));
        }
    }

    /**
     * @return array<string, string>
     */
    private static function defaultExecuteProbeCgiEnv(string $projectRoot, ?string $entryPath): array
    {
        $entryBase = null !== $entryPath ? basename($entryPath) : 'index.php';
        $scriptName = '/'.$entryBase;
        $env = [
            'REQUEST_METHOD' => 'GET',
            'SCRIPT_NAME' => $scriptName,
            'REQUEST_URI' => $scriptName,
        ];
        if (str_contains($entryPath ?? '', 'public/index.php')) {
            $env['QUERY_STRING'] = 'route=home';
            $env['REQUEST_URI'] = $scriptName.'?route=home';
        }

        return $env;
    }

    private static function defaultExecuteProbeShellLine(string $projectRoot, string $binaryRel): string
    {
        $entry = ProjectManifest::resolveEntryPath($projectRoot);
        $cgi = self::defaultExecuteProbeCgiEnv($projectRoot, $entry);
        $prefix = '';
        foreach (['QUERY_STRING', 'REQUEST_METHOD'] as $key) {
            if (isset($cgi[$key])) {
                $prefix .= $key.'='.$cgi[$key].' ';
            }
        }

        return trim($prefix).'./'.$binaryRel.' | wc -c';
    }

    /**
     * @param list<string> $files absolute paths
     *
     * @return list<array{class: string, file: string, methods: list<string>}>
     */
    private static function discoverUserClasses(array $files, string $projectRoot): array
    {
        $classes = [];
        foreach ($files as $path) {
            if (!is_file($path)) {
                continue;
            }
            $source = file_get_contents($path);
            if (false === $source || !preg_match('/^\s*(?:abstract\s+|final\s+)?class\s+(\w+)/m', $source, $classMatch)) {
                continue;
            }
            $className = $classMatch[1];
            $methods = [];
            if (preg_match_all('/\bfunction\s+(\w+)\s*\(/', $source, $methodMatches)) {
                $methods = array_values(array_unique($methodMatches[1]));
            }
            $classes[] = [
                'class' => $className,
                'file' => self::displayPath($projectRoot, $path),
                'methods' => $methods,
            ];
        }

        return $classes;
    }

    /**
     * @param array<string, list<Issue>> $issuesByFile
     *
     * @return list<string>
     */
    private static function collectBlockedKinds(array $issuesByFile): array
    {
        $all = [];
        foreach ($issuesByFile as $issues) {
            foreach (UnsupportedRegistry::uniqueKinds($issues) as $kind) {
                $all[$kind] = true;
            }
        }
        $kinds = array_keys($all);
        sort($kinds);

        return $kinds;
    }

    private static function displayPath(string $projectRoot, string $absolutePath): string
    {
        $formatted = ProjectGraph::formatFileList($projectRoot, [$absolutePath]);

        return $formatted[0] ?? $absolutePath;
    }
}
