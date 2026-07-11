<?php

declare(strict_types=1);

/**
 * Guard repo-root _config.yml so GitHub Pages Jekyll does not publish the whole tree.
 *
 *   php script/check-github-pages-config.php
 */

$root = dirname(__DIR__);
$configPath = $root.'/_config.yml';

if (!is_file($configPath)) {
    fwrite(STDERR, "check-github-pages-config: missing {$configPath}\n");
    exit(1);
}

$yaml = (string) file_get_contents($configPath);
$errors = [];

foreach (['docs/pages/PAGES.md', 'layouts_dir: docs/pages/_layouts', 'baseurl: "/php-compiler"'] as $needle) {
    if (!str_contains($yaml, $needle)) {
        $errors[] = "_config.yml: missing required setting {$needle}";
    }
}

$requiredExcludes = [
    'build',
    'prelinked',
    'lib',
    'test',
    'script',
    'vendor',
];

foreach ($requiredExcludes as $entry) {
    if (!preg_match('/^\s*-\s+'.preg_quote($entry, '/').'\s*$/m', $yaml)) {
        $errors[] = "_config.yml: exclude must list {$entry} (whole-repo Jekyll bloat breaks Pages deploy)";
    }
}

if ($errors !== []) {
    foreach ($errors as $err) {
        fwrite(STDERR, "check-github-pages-config: {$err}\n");
    }
    fwrite(STDERR, "check-github-pages-config: FAILED — see docs/pages/PAGES.md\n");
    exit(1);
}

fwrite(STDOUT, "check-github-pages-config: OK\n");
