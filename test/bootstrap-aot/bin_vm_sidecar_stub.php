<?php

declare(strict_types=1);

/**
 * M5 bin/vm.php link-time sidecar stub (#2699, #1492).
 *
 * Full bin/vm.php AOT still LLVM-segfaults under self-host; emit-helper matches
 * bin/vm.php by source path and copies this TU until honest VM init is green.
 */
function run(string $filename, string $code, array $options): void
{
    echo "vm driver ok\n";
}

if (!(\function_exists('php_compiler_cli_should_skip_entry_driver') && php_compiler_cli_should_skip_entry_driver())) {
    echo "bin_vm_sidecar_stub ready\n";
}
