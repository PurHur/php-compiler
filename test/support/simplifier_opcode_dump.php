<?php

declare(strict_types=1);

/**
 * Emit opcode dump for one source file under a chosen PHPCFG Simplifier mode.
 *
 * Usage: php test/support/simplifier_opcode_dump.php <abs-path.php> legacy|usechain
 *
 * Separate process per invocation so getenv() flags in vendored Simplifier are not
 * polluted by Runtime visitor caches in the parent (#36225).
 */

use PHPCompiler\Printer as OpCodePrinter;
use PHPCompiler\Runtime;

if ($argc < 3) {
    fwrite(STDERR, "usage: simplifier_opcode_dump.php <file.php> legacy|usechain\n");
    exit(2);
}

$file = $argv[1];
$mode = $argv[2];
if (!is_readable($file)) {
    fwrite(STDERR, "simplifier_opcode_dump: unreadable {$file}\n");
    exit(2);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';

if ('legacy' === $mode) {
    putenv('PHPCFG_SIMPLIFIER_USECHAIN=0');
    putenv('PHPCFG_SIMPLIFIER_LEGACY=1');
} elseif ('usechain' === $mode) {
    putenv('PHPCFG_SIMPLIFIER_USECHAIN=1');
    putenv('PHPCFG_SIMPLIFIER_LEGACY');
} else {
    fwrite(STDERR, "simplifier_opcode_dump: mode must be legacy or usechain, got {$mode}\n");
    exit(2);
}

$code = (string) file_get_contents($file);
$runtime = new Runtime();
$block = $runtime->compile($runtime->parse($code, $file));
if (null === $block) {
    fwrite(STDERR, "simplifier_opcode_dump: compile failed for {$file}\n");
    exit(1);
}

echo (new OpCodePrinter())->print($block);
