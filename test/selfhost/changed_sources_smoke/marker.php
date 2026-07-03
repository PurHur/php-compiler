<?php

declare(strict_types=1);

/**
 * Mutable marker for gen-N changed-sources probe (#15598).
 * The probe rewrites the return string and recompiles via gen-2 to prove working-tree emit.
 */

function changed_sources_smoke_marker(): string
{
    return 'changed_sources_marker_v1';
}
