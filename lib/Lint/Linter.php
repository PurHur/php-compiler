<?php

declare(strict_types=1);

namespace PHPCompiler\Lint;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Func as CfgFunc;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;
use PHPTypes\State;
use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\AOT\ProjectGraph;
use PHPCompiler\Runtime;
use PHPCompiler\Web\ConstStringFolder;
use PHPCompiler\Web\IncludePathResolver;
use PHPCompiler\Web\ProjectAutoload;
use PHPCompiler\Web\ProjectBootstrap;
use PHPCompiler\Web\ProjectManifest;

/**
 * Best-effort static compile check with line-accurate unsupported-syntax reporting.
 */
final class Linter
{
    private Runtime $runtime;

    /** @var list<string> */
    private array $dynamicIncludeWarnings = [];

    public function __construct(?Runtime $runtime = null)
    {
        $this->runtime = $runtime ?? new Runtime();
    }

    /**
     * @return list<string>
     */
    public function consumeDynamicIncludeWarnings(): array
    {
        $warnings = $this->dynamicIncludeWarnings;
        $this->dynamicIncludeWarnings = [];

        return $warnings;
    }

    /**
     * Lint an entry file and literal include/require targets reachable from it.
     *
     * @return list<Issue>
     */
    public function lintProject(string $entry): array
    {
        $issues = $this->lintFile($entry);
        [$projectDir, $manifest] = ProjectBootstrap::resolveFromScript($entry);
        if (null === $projectDir || null === $manifest) {
            return $issues;
        }

        $graph = ProjectGraph::resolve($projectDir);
        foreach ($graph['errors'] as $message) {
            $issues[] = new Issue($entry, 0, 'project-graph', $message, 154);
        }
        $extraFiles = $graph['files'];

        $entryReal = realpath($entry) ?: $entry;
        foreach ($extraFiles as $file) {
            $fileReal = realpath($file) ?: $file;
            if ($fileReal === $entryReal || !is_file($file)) {
                continue;
            }
            $issues = array_merge(
                $issues,
                $this->lintSource((string) file_get_contents($file), $file)
            );
        }

        return $this->dedupeIssues($issues);
    }

    /**
     * Lint every .php file under a directory (or a single file), merging results.
     *
     * @return list<Issue>
     */
    public function lintAll(string $path): array
    {
        if (is_file($path)) {
            return $this->lintFile($path);
        }
        if (!is_dir($path)) {
            throw new \InvalidArgumentException("Not a file or directory: {$path}");
        }
        $files = self::collectPhpFiles($path);
        if ([] === $files) {
            throw new \InvalidArgumentException("No .php files under {$path}");
        }
        $this->dynamicIncludeWarnings = [];
        $issues = [];
        foreach ($files as $file) {
            $issues = array_merge(
                $issues,
                $this->lintSource((string) file_get_contents($file), $file)
            );
        }

        return $this->dedupeIssues($issues);
    }

    /**
     * @return list<Issue>
     */
    public function lintFile(string $filename): array
    {
        if (!is_file($filename)) {
            throw new \InvalidArgumentException("Could not open file {$filename}");
        }
        $this->dynamicIncludeWarnings = [];

        return $this->lintSource((string) file_get_contents($filename), $filename);
    }

    /**
     * Lint a single file without following include/require (bootstrap inventory sweep).
     *
     * @return list<Issue>
     */
    public function lintFileStandalone(string $filename): array
    {
        if (!is_file($filename)) {
            throw new \InvalidArgumentException("Could not open file {$filename}");
        }
        $this->dynamicIncludeWarnings = [];

        return $this->lintSourceStandalone((string) file_get_contents($filename), $filename);
    }

    /**
     * @return list<Issue>
     */
    private function lintSourceStandalone(string $code, string $filename): array
    {
        try {
            $script = $this->parseForLint($code, $filename);
        } catch (\Throwable $e) {
            return $this->issuesFromParseFailure($e, $filename);
        }

        return $this->lintScript($script);
    }

    /**
     * @return list<Issue>
     */
    public function lintSource(string $code, string $filename): array
    {
        $issues = [];
        try {
            $script = $this->parseForLint($code, $filename);
        } catch (\Throwable $e) {
            return $this->issuesFromParseFailure($e, $filename);
        }
        foreach ($this->lintScript($script) as $issue) {
            $issues[] = $issue;
        }
        $queue = [$filename => $script];
        $seenFiles = [$filename => true];

        while ([] !== $queue) {
            $currentFile = array_key_first($queue);
            $currentScript = $queue[$currentFile];
            unset($queue[$currentFile]);

            foreach ($this->discoverIncludePaths($currentScript, $currentFile) as $includePath) {
                $resolved = IncludePathResolver::resolve($includePath, $currentFile);
                if (null === $resolved || isset($seenFiles[$resolved])) {
                    continue;
                }
                $seenFiles[$resolved] = true;
                try {
                    $included = $this->parseForLint(
                        (string) file_get_contents($resolved),
                        $resolved
                    );
                } catch (\Throwable $e) {
                    foreach ($this->issuesFromParseFailure($e, $resolved) as $issue) {
                        $issues[] = $issue;
                    }
                    continue;
                }
                foreach ($this->lintScript($included) as $issue) {
                    $issues[] = $issue;
                }
                $queue[$resolved] = $included;
            }
        }

        return $this->dedupeIssues($issues);
    }

    private function parseForLint(string $code, string $filename): Script
    {
        [$code, $bareRethrowLines] = $this->runtime->prepareSourceForParser($code, $filename);
        $this->runtime->compiler->setBareRethrowLines($bareRethrowLines);
        $this->runtime->resetParserNameResolverBeforeParse();
        $script = $this->runtime->parser->parse($code, $filename);
        $this->runtime->preprocessor->traverse($script);
        try {
            $this->runtime->typeReconstructor->resolve(new State($script));
        } catch (\LogicException $e) {
            // Type reconstruction may fail before compile; lint still runs the compiler pass.
        }
        $this->runtime->postprocessor->traverse($script);
        $this->runtime->detector->detect($script);

        return $script;
    }

    /**
     * Convert parse-level failures (php-cfg / parser pipeline) into lint issues.
     *
     * @return list<Issue>
     */
    private function issuesFromParseFailure(\Throwable $e, string $filename): array
    {
        $message = trim($e->getMessage());
        if ('' === $message) {
            $message = get_class($e);
        }
        $kind = Issue::kindFromMessage($message);

        return [
            new Issue(
                $filename,
                0,
                $kind,
                $message,
                \PHPCompiler\Lint\UnsupportedRegistry::trackingIssueForKind($kind)
            ),
        ];
    }

    /**
     * @return list<Issue>
     */
    private function lintScript(Script $script): array
    {
        $compiler = new LintCompiler();
        $this->runtime->compiler = $compiler;
        try {
            $this->runtime->compile($script);
        } catch (CompileFatal $e) {
            $compiler->issues[] = new Issue(
                $e->sourceFile,
                $e->sourceLine,
                $e->getMessage(),
                $e->getMessage()
            );
        } catch (\CompileError $e) {
            $compiler->issues[] = new Issue(
                $script->main->getFile(),
                0,
                $e->getMessage(),
                $e->getMessage()
            );
        } catch (\Throwable $e) {
            // Parse/type errors are outside lint scope; let callers handle separately.
        } finally {
            $this->runtime->compiler = new \PHPCompiler\Compiler();
        }

        return $compiler->issues;
    }

    /**
     * @param list<Issue> $issues
     * @return list<Issue>
     */
    private function dedupeIssues(array $issues): array
    {
        $out = [];
        $keys = [];
        foreach ($issues as $issue) {
            $key = $issue->file.'|'.$issue->line.'|'.$issue->kind;
            if (isset($keys[$key])) {
                continue;
            }
            $keys[$key] = true;
            $out[] = $issue;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function discoverIncludePaths(Script $script, string $entryFile): array
    {
        $paths = [];
        $seen = new \SplObjectStorage();
        $this->walkCfgBlock($script->main->cfg, $paths, $seen);
        foreach ($script->functions as $func) {
            if ($func instanceof CfgFunc && null !== $func->cfg) {
                $this->walkCfgBlock($func->cfg, $paths, $seen);
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param list<string> $paths
     */
    private function walkCfgBlock(CfgBlock $block, array &$paths, \SplObjectStorage $seen): void
    {
        if ($seen->contains($block)) {
            return;
        }
        $seen[$block] = true;

        foreach ($block->children as $child) {
            if ($child instanceof Op\Expr\Include_) {
                $literal = ConstStringFolder::foldForInclude($block, $child->expr, $child->getFile());
                if (null !== $literal) {
                    $paths[] = $literal;
                } else {
                    $line = $child->getLine();
                    $where = $line > 0 ? "line {$line}" : 'line ?';
                    $this->dynamicIncludeWarnings[] = "{$child->getFile()}: {$where}: dynamic include/require (not followed)";
                }
            }
            foreach ($child->getSubBlocks() as $name) {
                $sub = $child->{$name} ?? null;
                if ($sub instanceof CfgBlock) {
                    $this->walkCfgBlock($sub, $paths, $seen);
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function collectPhpFiles(string $dir): array
    {
        $root = realpath($dir);
        if (false === $root) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || 'php' !== strtolower($fileInfo->getExtension())) {
                continue;
            }
            $path = $fileInfo->getPathname();
            $resolved = realpath($path);
            $files[] = false !== $resolved ? $resolved : $path;
        }
        sort($files);

        return $files;
    }
}
