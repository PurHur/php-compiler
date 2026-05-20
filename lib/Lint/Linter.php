<?php

declare(strict_types=1);

namespace PHPCompiler\Lint;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Func as CfgFunc;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;
use PHPTypes\State;
use PHPCompiler\Runtime;

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
        return $this->lintFile($entry);
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
     * @return list<Issue>
     */
    public function lintSource(string $code, string $filename): array
    {
        $issues = [
            ...(new IncrementDetector())->detect($code, $filename),
            ...(new ListDestructuringDetector())->detect($code, $filename),
            ...(new SwitchDetector())->detect($code, $filename),
        ];
        $script = $this->parseForLint($code, $filename);
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
                $resolved = $this->resolveIncludePath($includePath, $currentFile);
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
     * @return list<Issue>
     */
    private function lintScript(Script $script): array
    {
        $compiler = new LintCompiler();
        $this->runtime->compiler = $compiler;
        try {
            $this->runtime->compile($script);
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
            if ($func instanceof CfgFunc) {
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
                $literal = $this->literalStringOperand($child->expr);
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

    private function literalStringOperand(Operand $operand): ?string
    {
        if ($operand instanceof Operand\Literal && is_string($operand->value)) {
            return $operand->value;
        }

        return null;
    }

    private function resolveIncludePath(string $path, string $fromFile): ?string
    {
        if ('' === $path) {
            return null;
        }
        if ($path[0] === '/' || (strlen($path) > 1 && $path[1] === ':')) {
            return is_file($path) ? $path : null;
        }
        $base = dirname($fromFile);
        $candidate = $base.'/'.$path;
        if (is_file($candidate)) {
            return realpath($candidate) ?: $candidate;
        }

        return null;
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
