<?php

declare(strict_types=1);

/**
 * SSOT for open stdlib JIT deferrals tracked by issue (#2465).
 *
 * Remove a builtin when its deferral issue closes; regenerate audit + capabilities.
 */

/**
 * @return array<string, array{issue: int, category: string}>
 */
function stdlib_jit_deferred_tracked(): array
{
    return [
        'spl_autoload_register' => ['issue' => 2441, 'category' => 'autoload'],
    ];
}

/**
 * @return array<string, string> builtin => category (for SelfHostBuiltinPolicy / audit)
 */
function stdlib_jit_deferred_by_category(): array
{
    $out = [];
    foreach (stdlib_jit_deferred_tracked() as $name => $meta) {
        $out[$name] = $meta['category'];
    }

    return $out;
}

function stdlib_jit_deferred_issue_for(string $name): ?int
{
    $key = strtolower($name);

    return stdlib_jit_deferred_tracked()[$key]['issue'] ?? null;
}
