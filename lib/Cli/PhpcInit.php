<?php

declare(strict_types=1);

namespace PHPCompiler\Cli;

/**
 * Scaffold a minimal lint-clean web project (issue #312).
 */
final class PhpcInit
{
    /** @var list<string> */
    private const TEMPLATE_FILES = [
        'phpc.json',
        'public/index.php',
        'README.md',
    ];

    public static function run(string $repoRoot, string $targetDir, bool $force): int
    {
        $target = $targetDir;
        if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
            fwrite(STDERR, "phpc init: cannot create directory: {$targetDir}\n");

            return 1;
        }

        $resolved = realpath($target);
        if (false === $resolved) {
            fwrite(STDERR, "phpc init: invalid target directory: {$targetDir}\n");

            return 1;
        }

        $templateBase = $repoRoot.'/templates/init';
        if (!is_dir($templateBase)) {
            fwrite(STDERR, "phpc init: templates missing at {$templateBase}\n");

            return 1;
        }

        foreach (self::TEMPLATE_FILES as $relative) {
            $source = $templateBase.'/'.$relative;
            if (!is_file($source)) {
                fwrite(STDERR, "phpc init: template missing: {$relative}\n");

                return 1;
            }

            $dest = $resolved.'/'.$relative;
            $destDir = dirname($dest);
            if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                fwrite(STDERR, "phpc init: cannot create directory: {$destDir}\n");

                return 1;
            }

            if (is_file($dest) && !$force) {
                fwrite(STDERR, "phpc init: already exists (use --force): {$relative}\n");

                return 1;
            }

            $contents = file_get_contents($source);
            if (false === $contents) {
                fwrite(STDERR, "phpc init: cannot read template: {$relative}\n");

                return 1;
            }

            if (false === file_put_contents($dest, $contents)) {
                fwrite(STDERR, "phpc init: cannot write: {$relative}\n");

                return 1;
            }
        }

        fwrite(STDOUT, "Initialized php-compiler project in {$resolved}\n");
        fwrite(STDOUT, "  phpc lint public/index.php\n");
        fwrite(STDOUT, "  phpc run public/index.php\n");

        return 0;
    }
}
