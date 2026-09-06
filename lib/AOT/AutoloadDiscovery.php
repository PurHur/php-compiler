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
 * implements, ::class, property types, trait uses, FQCN string property defaults,
 * and FQCN string coalesce defaults feeding `new $merged['k']`) and expands the
 * compile graph via phpc.json autoload.psr-4.
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
        $visiting = [];

        $visit = null;
        $visit = static function (string $file) use (
            &$visit,
            $runtime,
            $projectDir,
            $psr4Map,
            &$errors,
            &$files,
            &$seenFiles,
            &$visiting
        ): void {
            $resolved = realpath($file) ?: $file;
            if (isset($seenFiles[$resolved]) || !is_file($resolved)) {
                return;
            }
            if (isset($visiting[$resolved])) {
                return;
            }
            $visiting[$resolved] = true;

            try {
                $script = self::parseScript($runtime, $resolved);
            } catch (\Throwable) {
                unset($visiting[$resolved]);
                $seenFiles[$resolved] = true;

                return;
            }

            self::expandScriptReferences($script, $projectDir, $psr4Map, $visit, $errors);

            unset($visiting[$resolved]);
            if (isset($seenFiles[$resolved])) {
                return;
            }
            $seenFiles[$resolved] = true;
            // Post-order: traits/deps before the class that use-s them (#36382 Nyholm).
            $files[] = $resolved;
        };

        foreach ($seedFiles as $path) {
            $resolved = realpath($path) ?: $path;
            if (!is_file($resolved) || isset($seenFiles[$resolved])) {
                continue;
            }
            // Seeds are already on the compile list; only expand their references.
            $seenFiles[$resolved] = true;
            try {
                $script = self::parseScript($runtime, $resolved);
            } catch (\Throwable) {
                continue;
            }
            self::expandScriptReferences($script, $projectDir, $psr4Map, $visit, $errors);
        }

        return ['files' => $files, 'errors' => $errors];
    }

    /**
     * Expand hard class refs (error if missing) and soft FQCN string defaults
     * (skip silently when the optional package is not installed — #36382 Slim backends).
     *
     * @param array<string, string|list<string>> $psr4Map
     * @param callable(string): void             $visit
     * @param list<string>                       $errors
     */
    private static function expandScriptReferences(
        Script $script,
        string $projectDir,
        array $psr4Map,
        callable $visit,
        array &$errors
    ): void {
        $refs = StaticClassReferenceScanner::fromScript($script);
        foreach ($refs['hard'] as $className) {
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
            $visit($resolvedPath);
        }
        foreach ($refs['soft'] as $className) {
            if (!self::isPsr4Candidate($className, $psr4Map)) {
                continue;
            }
            $resolvedPath = ProjectAutoload::resolveClassPath($className, $psr4Map);
            if (null === $resolvedPath) {
                // Optional Composer backends (Slim\Psr7, Guzzle, …) share a PSR-4 prefix
                // but are not installed — class_exists() stays false at runtime (#36382).
                continue;
            }
            $visit($resolvedPath);
        }
    }

    /**
     * @param array<string, string|list<string>> $psr4Map
     */
    private static function isPsr4Candidate(string $className, array $psr4Map): bool
    {
        foreach ($psr4Map as $prefix => $_base) {
            if (is_string($prefix) && str_starts_with($className, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string|list<string>> $psr4Map
     */
    private static function expectedRelativePath(string $projectDir, string $className, array $psr4Map): ?string
    {
        $candidates = [];
        foreach ($psr4Map as $prefix => $baseDirs) {
            if (!is_string($prefix) || !str_starts_with($className, $prefix)) {
                continue;
            }
            $candidates[] = [$prefix, ProjectAutoload::normalizeBaseDirs($baseDirs)];
        }
        usort(
            $candidates,
            static fn (array $a, array $b): int => strlen($b[0]) <=> strlen($a[0])
        );

        foreach ($candidates as [$prefix, $dirs]) {
            $relative = substr($className, strlen($prefix));
            if ('' === $relative || [] === $dirs) {
                continue;
            }
            $relPath = str_replace('\\', '/', $relative).'.php';
            $absolute = rtrim($dirs[0], '/\\').'/'.$relPath;
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
     * @return array{hard: list<string>, soft: list<string>}
     */
    public static function fromScript(Script $script): array
    {
        $hard = [];
        $soft = [];
        $seen = new \SplObjectStorage();
        if ($script->main->cfg instanceof CfgBlock) {
            self::walkBlock($script->main->cfg, $hard, $soft, $seen);
        }
        foreach ($script->functions as $func) {
            if ($func instanceof CfgFunc && $func->cfg instanceof CfgBlock) {
                self::walkBlock($func->cfg, $hard, $soft, $seen);
            }
        }

        return [
            'hard' => array_values(array_unique($hard)),
            'soft' => array_values(array_unique($soft)),
        ];
    }

    /**
     * @param list<string> $hard
     * @param list<string> $soft
     */
    private static function walkBlock(
        CfgBlock $block,
        array &$hard,
        array &$soft,
        \SplObjectStorage $seen
    ): void {
        if ($seen->contains($block)) {
            return;
        }
        $seen[$block] = true;

        foreach ($block->children as $child) {
            if ($child instanceof Op\Expr\New_) {
                self::collectOperand($child->class, $hard);
            } elseif ($child instanceof Op\Expr\StaticCall) {
                self::collectOperand($child->class, $hard);
            } elseif ($child instanceof Op\Expr\StaticPropertyFetch) {
                self::collectOperand($child->class, $hard);
            } elseif ($child instanceof Op\Expr\ClassConstFetch) {
                self::collectOperand($child->class, $hard);
            } elseif ($child instanceof Op\Expr\InstanceOf_) {
                self::collectOperand($child->class, $hard);
            } elseif ($child instanceof Op\Stmt\Class_) {
                self::collectOperand($child->extends, $hard);
                foreach ($child->implements as $iface) {
                    self::collectOperand($iface, $hard);
                }
            } elseif ($child instanceof Op\Stmt\Property) {
                // Property types (e.g. Slim CallableResolver::$container : ?ContainerInterface)
                // are not new/static/extends refs — without this, reachable Composer graphs omit
                // PSR interfaces and AOT dies on $container->has() (#36382).
                self::collectDeclaredType($child->declaredType ?? null, $hard);
                // String FQCN defaults (Slim NyholmPsr17Factory::$serverRequestCreatorClass =
                // 'Nyholm\Psr7Server\ServerRequestCreator') feed class_exists() / new $cls at
                // runtime — without collecting them, reachable closure omits the class and
                // ServerRequestCreatorFactory::create() finds no implementation (#36382).
                // Soft: unresolved optionals (Slim\Psr7, Guzzle, …) must not fail the graph.
                self::collectFqcnStringDefault($child->defaultVar ?? null, $soft);
            } elseif ($child instanceof Op\Expr\BinaryOp\Coalesce) {
                // FastRoute simpleDispatcher: `$merged['routeCollector'] ?? 'FastRoute\RouteCollector'`
                // then `new $merged['routeCollector']`. The New_ class operand is dynamic, so only
                // the coalesce RHS FQCN literal seeds AutoloadDiscovery — without it, reachable
                // graphs keep functions.php but omit RouteCollector and AOT aborts after START (#36382).
                self::collectFqcnStringDefault($child->right ?? null, $soft);
            } elseif ($child instanceof Op\Expr\Param) {
                self::collectDeclaredType($child->declaredType ?? null, $hard);
            } elseif ($child instanceof Op\Stmt\TraitUse) {
                // Nyholm Request uses MessageTrait/RequestTrait — without this, reachable
                // Composer graphs omit traits and AOT dies with "Trait not found" (#36382).
                foreach ($child->traits as $trait) {
                    self::collectOperand($trait, $hard);
                }
            } elseif ($child instanceof Op\Stmt\Interface_) {
                foreach ($child->extends as $parent) {
                    self::collectOperand($parent, $hard);
                }
            } elseif ($child instanceof Op\Stmt\Enum_) {
                foreach ($child->implements as $iface) {
                    self::collectOperand($iface, $hard);
                }
            } elseif ($child instanceof Op\Stmt\Function_) {
                if ($child->func->cfg instanceof CfgBlock) {
                    self::walkBlock($child->func->cfg, $hard, $soft, $seen);
                }
            }

            OpSubBlockAccess::walkSubBlocks($child, static function (CfgBlock $sub) use (&$hard, &$soft, $seen): void {
                self::walkBlock($sub, $hard, $soft, $seen);
            });
        }
    }

    /**
     * @param list<string> $names
     */
    private static function collectDeclaredType(mixed $type, array &$names): void
    {
        if (null === $type) {
            return;
        }
        if ($type instanceof Op\Type\Literal) {
            $normalized = self::normalizeClassName((string) $type->name);
            if (null !== $normalized) {
                $names[] = $normalized;
            }

            return;
        }
        if ($type instanceof Op\Type\Nullable) {
            self::collectDeclaredType($type->subtype ?? null, $names);

            return;
        }
        if ($type instanceof Op\Type\Reference) {
            self::collectOperand($type->declaration ?? null, $names);

            return;
        }
        if ($type instanceof Op\Type\Intersection) {
            foreach (($type->types ?? []) as $part) {
                self::collectDeclaredType($part, $names);
            }
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

    /**
     * Collect property-default string literals that look like FQCNs (contain `\`).
     * Method-name defaults like `'fromGlobals'` are intentionally skipped (#36382).
     *
     * @param list<string> $names
     */
    private static function collectFqcnStringDefault(?Operand $operand, array &$names): void
    {
        $className = self::literalClassName($operand);
        if (null === $className || !str_contains($className, '\\')) {
            return;
        }
        $names[] = $className;
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
