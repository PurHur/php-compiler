<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPCfg\Block as CfgBlock;
use PHPCfg\Func as CfgFunc;
use PHPCfg\Operand;
use PHPCfg\Op;
use PHPCfg\Script;
use PHPCompiler\Cfg\OpSubBlockAccess;
use PHPCompiler\Runtime;
use PHPCompiler\Web\ProjectAutoload;
use PHPCompiler\Web\ProjectManifest;

/**
 * Static PSR-4 class reference discovery for phpc build --project (issue #1803).
 *
 * Walks entry/includes for syntactic class references (new, static call, extends,
 * implements, ::class) and expands the compile graph via phpc.json autoload.psr-4.
 */
final class AutoloadDiscovery
{
    /**
     * @param list<string> $seedFiles absolute paths (entry + literal/manifest includes)
     *
     * @return array{files: list<string>, errors: list<string>}
     */
    public static function discover(
        Runtime $runtime,
        string $projectDir,
        array $psr4Map,
        array $seedFiles
    ): array {
        if ([] === $psr4Map) {
            return ['files' => [], 'errors' => []];
        }

        $errors = [];
        $files = [];
        $seenFiles = [];
        $queue = [];

        foreach ($seedFiles as $path) {
            $resolved = realpath($path) ?: $path;
            if (!is_file($resolved) || isset($seenFiles[$resolved])) {
                continue;
            }
            $seenFiles[$resolved] = true;
            $queue[] = $resolved;
        }

        while ([] !== $queue) {
            $file = array_shift($queue);
            if (!is_file($file)) {
                continue;
            }

            try {
                $script = self::parseScript($runtime, $file);
            } catch (\Throwable) {
                continue;
            }

            foreach (StaticClassReferenceScanner::fromScript($script) as $className) {
                if (!self::isPsr4Candidate($className, $psr4Map)) {
                    continue;
                }

                $resolvedPath = ProjectAutoload::resolveClassPath($className, $psr4Map);
                if (null === $resolvedPath) {
                    $expected = self::expectedRelativePath($projectDir, $className, $psr4Map);
                    $errors[] = 'autoload: unresolved class '.$className
                        .($expected ? ' (expected '.$expected.')' : '');

                    continue;
                }

                $key = realpath($resolvedPath) ?: $resolvedPath;
                if (isset($seenFiles[$key])) {
                    continue;
                }
                $seenFiles[$key] = true;
                $files[] = $key;
                $queue[] = $key;
            }
        }

        return ['files' => $files, 'errors' => $errors];
    }

    /**
     * @param array<string, string> $psr4Map
     */
    private static function isPsr4Candidate(string $className, array $psr4Map): bool
    {
        foreach ($psr4Map as $prefix => $_base) {
            if (str_starts_with($className, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $psr4Map
     */
    private static function expectedRelativePath(string $projectDir, string $className, array $psr4Map): ?string
    {
        foreach ($psr4Map as $prefix => $baseDir) {
            if (!str_starts_with($className, $prefix)) {
                continue;
            }
            $relative = substr($className, strlen($prefix));
            if ('' === $relative) {
                return null;
            }
            $base = rtrim($baseDir, '/\\');
            $absolute = $base.'/'.str_replace('\\', '/', $relative).'.php';
            $root = ProjectManifest::resolveProjectDir($projectDir) ?? $projectDir;
            $display = ProjectGraph::formatFileList($root, [$absolute]);

            return $display[0] ?? null;
        }

        return null;
    }

    private static function parseScript(Runtime $runtime, string $file): Script
    {
        $code = (string) file_get_contents($file);

        return $runtime->parse($code, $file);
    }
}

/** Extract compile-time class name literals from a PHPCfg script. */
final class StaticClassReferenceScanner
{
    /** @var list<string> */
    private const RESERVED_TYPE_NAMES = [
        'array', 'bool', 'callable', 'false', 'float', 'int', 'iterable', 'mixed',
        'never', 'null', 'object', 'self', 'parent', 'static', 'string', 'true', 'void',
    ];
    /**
     * @return list<string>
     */
    public static function fromScript(Script $script): array
    {
        $names = [];
        $seen = new \SplObjectStorage();
        if ($script->main->cfg instanceof CfgBlock) {
            self::walkBlock($script->main->cfg, $names, $seen);
        }
        foreach ($script->functions as $func) {
            if ($func instanceof CfgFunc && $func->cfg instanceof CfgBlock) {
                self::walkBlock($func->cfg, $names, $seen);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param list<string> $names
     */
    private static function walkBlock(CfgBlock $block, array &$names, \SplObjectStorage $seen): void
    {
        if ($seen->contains($block)) {
            return;
        }
        $seen[$block] = true;

        foreach ($block->children as $child) {
            if ($child instanceof Op\Expr\New_) {
                self::collectOperand($child->class, $names);
            } elseif ($child instanceof Op\Expr\StaticCall) {
                self::collectOperand($child->class, $names);
            } elseif ($child instanceof Op\Expr\StaticPropertyFetch) {
                self::collectOperand($child->class, $names);
            } elseif ($child instanceof Op\Expr\ClassConstFetch) {
                self::collectOperand($child->class, $names);
            } elseif ($child instanceof Op\Expr\InstanceOf_) {
                self::collectOperand($child->class, $names);
            } elseif ($child instanceof Op\Stmt\Class_) {
                self::collectOperand($child->extends, $names);
                foreach ($child->implements as $iface) {
                    self::collectOperand($iface, $names);
                }
            } elseif ($child instanceof Op\Stmt\Interface_) {
                foreach ($child->extends as $parent) {
                    self::collectOperand($parent, $names);
                }
            } elseif ($child instanceof Op\Stmt\Enum_) {
                foreach ($child->implements as $iface) {
                    self::collectOperand($iface, $names);
                }
            } elseif ($child instanceof Op\Stmt\Function_) {
                if ($child->func->cfg instanceof CfgBlock) {
                    self::walkBlock($child->func->cfg, $names, $seen);
                }
            }

            OpSubBlockAccess::walkSubBlocks($child, static function (CfgBlock $sub) use (&$names, $seen): void {
                self::walkBlock($sub, $names, $seen);
            });
        }
    }

    /**
     * @param list<string> $names
     */
    private static function collectOperand(?Operand $operand, array &$names): void
    {
        $className = self::literalClassName($operand);
        if (null !== $className) {
            $names[] = $className;
        }
    }

    private static function literalClassName(?Operand $operand): ?string
    {
        if (null === $operand) {
            return null;
        }

        if ($operand instanceof Operand\Literal && is_string($operand->value)) {
            return self::normalizeClassName($operand->value);
        }

        if ($operand instanceof Operand\Temporary) {
            $original = $operand->original;
            if ($original instanceof Operand\Literal && is_string($original->value)) {
                return self::normalizeClassName($original->value);
            }
        }

        return null;
    }

    private static function normalizeClassName(string $name): ?string
    {
        $name = ltrim($name, '\\');
        if ('' === $name) {
            return null;
        }
        if (in_array(strtolower($name), self::RESERVED_TYPE_NAMES, true)) {
            return null;
        }

        return $name;
    }
}
