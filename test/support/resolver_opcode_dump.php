<?php

declare(strict_types=1);

/**
 * Emit opcode dump for one source file under a chosen PHPTYPES resolver mode.
 *
 * Usage: php test/support/resolver_opcode_dump.php <abs-path.php> legacy|worklist
 */

use PHPCompiler\Printer as OpCodePrinter;
use PHPCompiler\Runtime;

if ($argc < 3) {
    fwrite(STDERR, "usage: resolver_opcode_dump.php <file.php> legacy|worklist\n");
    exit(2);
}

$file = $argv[1];
$mode = $argv[2];
if (! is_readable($file)) {
    fwrite(STDERR, "resolver_opcode_dump: unreadable {$file}\n");
    exit(2);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';

if ('legacy' === $mode) {
    putenv('PHPTYPES_RESOLVER_WORKLIST=0');
    putenv('PHPTYPES_RESOLVER_LEGACY=1');
} elseif ('worklist' === $mode) {
    putenv('PHPTYPES_RESOLVER_WORKLIST=1');
    putenv('PHPTYPES_RESOLVER_LEGACY');
} else {
    fwrite(STDERR, "resolver_opcode_dump: mode must be legacy or worklist, got {$mode}\n");
    exit(2);
}

$runtime = new Runtime();
$code = (string) file_get_contents($file);
$block = $runtime->compile($runtime->parse($code, $file));
echo (new OpCodePrinter())->print($block);
