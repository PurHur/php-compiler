#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * PHPCfg parse smoke for bootstrap spine / Phase A inventory (#2575).
 *
 * Catches vendor php-cfg failures (e.g. Expr_ArrowFunction) before bin/compile.php -o.
 *
 * Usage:
 *   php script/bootstrap-spine-php-cfg-parse-check.php           # all vm.php-path files
 *   php script/bootstrap-spine-php-cfg-parse-check.php --minimal # compiler_minimal require_once closure
 *   php script/bootstrap-spine-php-cfg-parse-check.php --file lib/JIT.php
 */

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPCfg\Parser;
use PHPCompiler\Ast\GroupUseStripper;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
require __DIR__.'/bootstrap-lib.php';

$minimal = in_array('--minimal', $argv, true);
$fileArg = null;
foreach ($argv as $i => $arg) {
    if ('--file' === $arg && isset($argv[$i + 1])) {
        $fileArg = $argv[$i + 1];
    }
}

/**
 * @return list<string> absolute paths
 */
function bootstrap_spine_phpcfg_files_from_minimal(string $root): array
{
    $main = $root.'/test/selfhost/compiler_minimal/main.php';
    if (!is_file($main)) {
        fwrite(STDERR, "bootstrap-spine-php-cfg-parse-check: missing {$main}\n");

        exit(1);
    }
    $source = (string) file_get_contents($main);
    if (!preg_match_all(
        "/require_once\\s+__DIR__\\s*\\.\\s*'([^']+\\.php)'\\s*;/",
        $source,
        $matches
    )) {
        return [];
    }
    $files = [$main => true];
    $base = dirname($main);
    foreach ($matches[1] as $rel) {
        $path = realpath($base.'/'.$rel);
        if (false !== $path && is_file($path)) {
            $files[$path] = true;
        }
    }

    return array_keys($files);
}

/**
 * @return list<string> absolute paths
 */
function bootstrap_spine_phpcfg_resolve_targets(string $root, bool $minimal, ?string $fileArg): array
{
    if (null !== $fileArg) {
        $path = str_starts_with($fileArg, '/') ? $fileArg : $root.'/'.$fileArg;
        if (!is_file($path)) {
            fwrite(STDERR, "bootstrap-spine-php-cfg-parse-check: not a file: {$path}\n");

            exit(1);
        }

        return [$path];
    }
    if ($minimal) {
        return bootstrap_spine_phpcfg_files_from_minimal($root);
    }

    return bootstrapVmPathPhpFiles($root);
}

function bootstrap_spine_phpcfg_make_parser(): Parser
{
    $astTraverser = new NodeTraverser();
    $astTraverser->addVisitor(new NameResolver());
    $astTraverser->addVisitor(new GroupUseStripper());

    return new Parser(
        (new ParserFactory())->create(ParserFactory::ONLY_PHP7),
        $astTraverser
    );
}

/**
 * @return array{kind: string, detail: string}|null
 */
function bootstrap_spine_phpcfg_parse_file(Parser $parser, string $path): ?array
{
    $code = file_get_contents($path);
    if (!is_string($code)) {
        return ['kind' => 'read', 'detail' => 'could not read file'];
    }
    try {
        $parser->parse($code, $path);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (preg_match('/Unknown (Expr|Stmt) Type ([A-Za-z0-9_]+)/', $msg, $m)) {
            return ['kind' => $m[1], 'detail' => $m[2]];
        }
        if (preg_match('/Unknown (Operand|UnaryOp|BinaryOp|CastOp) ([A-Za-z0-9_]+)/', $msg, $m)) {
            return ['kind' => $m[1], 'detail' => $m[2]];
        }

        return ['kind' => 'parse', 'detail' => $msg];
    }

    return null;
}

$targets = bootstrap_spine_phpcfg_resolve_targets($root, $minimal, $fileArg);
if ([] === $targets) {
    fwrite(STDERR, "bootstrap-spine-php-cfg-parse-check: no files to scan\n");
    exit(1);
}

$parser = bootstrap_spine_phpcfg_make_parser();
$failures = [];
foreach ($targets as $path) {
    $failure = bootstrap_spine_phpcfg_parse_file($parser, $path);
    if (null !== $failure) {
        $rel = str_starts_with($path, $root.'/') ? substr($path, strlen($root) + 1) : $path;
        $failures[] = [
            'path' => $rel,
            'kind' => $failure['kind'],
            'detail' => $failure['detail'],
        ];
    }
}

$scope = $minimal ? 'minimal' : (null !== $fileArg ? 'file' : 'phase_a');
if ([] !== $failures) {
    fwrite(STDERR, "bootstrap-spine-php-cfg-parse-check: FAILED ({$scope}, ".count($failures).' of '.count($targets)." files)\n");
    foreach ($failures as $row) {
        fwrite(
            STDERR,
            "  {$row['path']}: PHPCfg {$row['kind']} {$row['detail']}\n"
        );
    }
    exit(1);
}

fwrite(
    STDOUT,
    "bootstrap-spine-php-cfg-parse-check: OK ({$scope}, ".count($targets)." files)\n"
);
exit(0);
