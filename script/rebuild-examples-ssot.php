<?php

declare(strict_types=1);

/**
 * Shared needles for script/rebuild-examples.php and drift guards (issues #2183, #2334).
 */

/**
 * Benchmark sub-row label for 003-MiniWebApp project JIT (bin/jit.php index.php from public/).
 */
function rebuild_examples_miniwebapp_jit_project_row_label(): string
{
    return '003-MiniWebApp (project JIT)';
}
