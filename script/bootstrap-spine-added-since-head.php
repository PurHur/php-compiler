#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$cur = file($root.'/test/selfhost/compiler_lib_spine_smoke/main.php', FILE_IGNORE_NEW_LINES) ?: [];
$base = explode("\n", (string) shell_exec('git show HEAD:test/selfhost/compiler_lib_spine_smoke/main.php'));

$extract = static function (array $lines): array {
    $paths = [];
    foreach ($lines as $line) {
        if (preg_match("#require_once __DIR__\\.'/\\.\\./\\.\\./\\.\\./([^']+)';#", $line, $m)) {
            $paths[$m[1]] = true;
        }
    }

    return $paths;
};

$added = array_diff_key($extract($cur), $extract($base));
$rels = array_keys($added);
sort($rels, SORT_STRING);
fwrite(STDOUT, count($rels)." added vs master HEAD\n");
foreach ($rels as $rel) {
    fwrite(STDOUT, "  {$rel}\n");
}
