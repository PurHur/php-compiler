#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * How splittable is the bootstrap spine? (#22642 follow-up)
 *
 * The gen-0 rebuild compiles test/selfhost/compiler_lib_spine_smoke/main.php — 6.5k literal
 * require_once lines — as ONE translation unit, single-threaded, for hours. The obvious
 * remedy is the split-compilation shape already proven for helper units (#15889): per-chunk
 * .o with a content fingerprint, emitted in parallel, merged at link.
 *
 * The risk is not the chunking, it is the edges. `JIT\Call\ExternalMethod` lowers a method
 * call on a class that is NOT in the current module to `__value__writeNull` — silently
 * (#579). Split the spine naively and every cross-chunk call becomes a silent null: a
 * miscompile far worse than a slow build.
 *
 * This measures that surface statically, before anyone commits to a partitioning:
 *
 *   - which spine file declares which class
 *   - which classes each file references (new, static call, extends/implements, use,
 *     instanceof, catch, param/return types, attributes)
 *   - for a candidate partition, how many references cross a chunk boundary
 *
 * Cross-chunk edges are what a split has to resolve by declaration-binding (as
 * HelperRuntimeCache::tryProvide does) rather than by re-lowering. A partition with few
 * edges is cheap to make safe; one with many is not worth attempting.
 *
 * Usage:
 *   php script/spine-split-probe.php                 # per-strategy summary
 *   php script/spine-split-probe.php --json
 *   php script/spine-split-probe.php --strategy=dir  # dir (default) | top | ext
 *   php script/spine-split-probe.php --worst=15      # list the most-entangled chunks
 */

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

$json = in_array('--json', $argv, true);
$strategy = 'dir';
$worst = 10;
foreach ($argv as $arg) {
    if (str_starts_with((string) $arg, '--strategy=')) {
        $strategy = substr((string) $arg, 11);
    }
    if (str_starts_with((string) $arg, '--worst=')) {
        $worst = max(0, (int) substr((string) $arg, 8));
    }
}

$spineEntry = $root.'/test/selfhost/compiler_lib_spine_smoke/main.php';
if (!is_file($spineEntry)) {
    fwrite(STDERR, "spine-split-probe: missing {$spineEntry}\n");
    exit(2);
}

/** Spine require list, in order, as repo-relative paths. */
$rels = [];
foreach (file($spineEntry, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (preg_match("#require_once __DIR__\\.'(?:/\\.\\.)+/([^']+)'#", $line, $m)) {
        $rels[] = $m[1];
    }
}
$rels = array_values(array_unique($rels));
if ([] === $rels) {
    fwrite(STDERR, "spine-split-probe: no require_once paths in spine entry\n");
    exit(2);
}

/**
 * Directories big enough that leaving them whole caps parallelism, mapped to how many
 * sub-chunks to cut them into. ext/standard alone is ~2.1k files — a third of the spine —
 * so however many chunks the rest is cut into, it decides wall time on its own.
 *
 * Sub-chunking is by first letter of the basename: arbitrary, but these are one-builtin-per-file
 * leaves, so any balanced partition behaves the same. `--strategy=sub` reports what that costs
 * in extra cross-chunk edges.
 */
const SPINE_SUBSPLIT = [
    'ext/standard' => true,
    'lib/JIT' => true,
    'lib/VM' => true,
];

/** Chunk key for a repo-relative path under the chosen strategy. */
$chunkOf = static function (string $rel) use ($strategy): string {
    $parts = explode('/', $rel);
    $dir = \count($parts) > 2 ? $parts[0].'/'.$parts[1] : $parts[0];

    return match ($strategy) {
        // One chunk per top-level tree: lib, ext, …
        'top' => $parts[0],
        // One chunk per ext module, lib/<sub> otherwise.
        'ext' => 'ext' === $parts[0] ? 'ext/'.($parts[1] ?? '_') : 'lib',
        // Per-directory, but cut the few oversized directories into letter buckets so no
        // single chunk dominates wall time (#22642 follow-up).
        'sub' => isset(SPINE_SUBSPLIT[$dir])
            ? $dir.'#'.strtoupper(substr(basename($rel), 0, 1))
            : $dir,
        // Same cut, except the shared Vm* implementation classes stay together as one hub
        // chunk per directory. The oversized directories are hub-and-leaf: many one-builtin
        // leaf files calling a small Vm* core, so a letter cut severs every leaf from its hub.
        'hub' => isset(SPINE_SUBSPLIT[$dir])
            ? (str_starts_with(basename($rel), 'Vm')
                ? $dir.'#hub'
                : $dir.'#'.strtoupper(substr(basename($rel), 0, 1)))
            : $dir,
        // Default: one chunk per immediate directory — the natural build unit.
        default => $dir,
    };
};

final class SymbolVisitor extends NodeVisitorAbstract
{
    /** @var list<string> */
    public array $declared = [];

    /** @var array<string,true> */
    public array $referenced = [];

    private string $namespace = '';

    private function note(?Node $name): void
    {
        if (!$name instanceof Node\Name) {
            return;
        }
        $n = ltrim($name->toString(), '\\');
        // Relative names resolve against the current namespace; unqualified builtins
        // (Throwable, Closure) are not spine classes and drop out of the join anyway.
        if ('' !== $this->namespace && !$name->isFullyQualified() && !str_contains($n, '\\')) {
            $n = $this->namespace.'\\'.$n;
        }
        if ('' !== $n && !in_array(strtolower($n), ['self', 'static', 'parent'], true)) {
            $this->referenced[$n] = true;
        }
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->namespace = $node->name?->toString() ?? '';

            return null;
        }
        if ($node instanceof Node\Stmt\ClassLike && null !== $node->name) {
            $this->declared[] = ('' !== $this->namespace ? $this->namespace.'\\' : '').$node->name->toString();
        }
        if ($node instanceof Node\Stmt\Class_) {
            $this->note($node->extends);
            foreach ($node->implements as $i) {
                $this->note($i);
            }
        }
        if ($node instanceof Node\Stmt\Interface_) {
            foreach ($node->extends as $e) {
                $this->note($e);
            }
        }
        if ($node instanceof Node\Stmt\TraitUse) {
            foreach ($node->traits as $t) {
                $this->note($t);
            }
        }
        if ($node instanceof Node\Expr\New_ || $node instanceof Node\Expr\StaticCall
            || $node instanceof Node\Expr\StaticPropertyFetch || $node instanceof Node\Expr\ClassConstFetch
            || $node instanceof Node\Expr\Instanceof_) {
            /** @var Node|null $cls */
            $cls = $node->class ?? null;
            $this->note($cls instanceof Node\Name ? $cls : null);
        }
        if ($node instanceof Node\Stmt\Catch_) {
            foreach ($node->types as $t) {
                $this->note($t);
            }
        }
        if ($node instanceof Node\Param) {
            $this->noteType($node->type);
        }
        if ($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_) {
            $this->noteType($node->returnType);
        }
        if ($node instanceof Node\Stmt\Property) {
            $this->noteType($node->type);
        }

        return null;
    }

    private function noteType(?Node $type): void
    {
        if ($type instanceof Node\Name) {
            $this->note($type);
        } elseif ($type instanceof Node\NullableType) {
            $this->noteType($type->type);
        } elseif ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $t) {
                $this->noteType($t);
            }
        }
    }
}

$parser = (new ParserFactory())->createForNewestSupportedVersion();

$declaredBy = [];   // class => rel
$refsOf = [];       // rel => list<class>
$unparsed = 0;

foreach ($rels as $rel) {
    $abs = $root.'/'.$rel;
    if (!is_file($abs) || !str_ends_with($rel, '.php')) {
        continue;
    }
    try {
        $ast = $parser->parse((string) file_get_contents($abs));
    } catch (\Throwable) {
        ++$unparsed;

        continue;
    }
    $v = new SymbolVisitor();
    $t = new NodeTraverser();
    $t->addVisitor($v);
    $t->traverse($ast ?? []);
    foreach ($v->declared as $c) {
        $declaredBy[$c] = $rel;
    }
    $refsOf[$rel] = array_keys($v->referenced);
}

// Resolve references to spine files, then to chunks.
$chunkFiles = [];
foreach ($rels as $rel) {
    $chunkFiles[$chunkOf($rel)][] = $rel;
}

$internal = 0;
$cross = 0;
$external = 0;                 // not declared anywhere in the spine (builtin / vendor)
$crossPairs = [];              // "from>to" => count
$crossByChunk = [];            // chunk => count

foreach ($refsOf as $rel => $classes) {
    $from = $chunkOf($rel);
    foreach ($classes as $cls) {
        $target = $declaredBy[$cls] ?? null;
        if (null === $target) {
            ++$external;

            continue;
        }
        $to = $chunkOf($target);
        if ($to === $from) {
            ++$internal;

            continue;
        }
        ++$cross;
        $crossPairs[$from.' > '.$to] = ($crossPairs[$from.' > '.$to] ?? 0) + 1;
        $crossByChunk[$from] = ($crossByChunk[$from] ?? 0) + 1;
    }
}

arsort($crossPairs);
arsort($crossByChunk);

$result = [
    'strategy' => $strategy,
    'spine_files' => \count($rels),
    'unparsed' => $unparsed,
    'chunks' => \count($chunkFiles),
    'classes_declared' => \count($declaredBy),
    'refs_internal' => $internal,
    'refs_cross_chunk' => $cross,
    'refs_external_to_spine' => $external,
    'cross_ratio' => $internal + $cross > 0 ? round($cross / ($internal + $cross), 4) : 0.0,
    'largest_chunk_files' => max(array_map('count', $chunkFiles)),
    'worst_chunks' => \array_slice($crossByChunk, 0, $worst, true),
    'worst_pairs' => \array_slice($crossPairs, 0, $worst, true),
];

if ($json) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    exit(0);
}

printf("spine-split-probe: strategy=%s  %d files → %d chunks (largest %d files)\n",
    $strategy, $result['spine_files'], $result['chunks'], $result['largest_chunk_files']);
printf("  classes declared in spine : %d\n", $result['classes_declared']);
printf("  refs within a chunk       : %d\n", $internal);
printf("  refs CROSSING chunks      : %d  (%.1f%% of intra-spine refs)\n", $cross, 100 * $result['cross_ratio']);
printf("  refs outside the spine    : %d  (builtin/vendor — unaffected by chunking)\n", $external);
if ($unparsed > 0) {
    printf("  unparsed files            : %d\n", $unparsed);
}
if ([] !== $result['worst_chunks']) {
    echo "\n  most entangled chunks (outgoing cross-chunk refs):\n";
    foreach ($result['worst_chunks'] as $chunk => $n) {
        printf("    %-28s %d\n", $chunk, $n);
    }
}
if ([] !== $result['worst_pairs']) {
    echo "\n  heaviest chunk pairs:\n";
    foreach ($result['worst_pairs'] as $pair => $n) {
        printf("    %-46s %d\n", $pair, $n);
    }
}
echo "\n  Every crossing ref is an edge a split must bind by declaration (as\n";
echo "  HelperRuntimeCache::tryProvide does) instead of re-lowering. Left unbound it\n";
echo "  lowers to __value__writeNull and miscompiles silently (#579) — count them with\n";
echo "  PHP_COMPILER_REPORT_EXTERNAL_STUBS=1 on a real chunk build.\n";
