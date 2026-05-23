<?php

declare(strict_types=1);

/**
 * Zend polyfill for bootstrap-aot-link (phpc_deploy_path is compiler-only otherwise).
 */
function phpc_deploy_path(string $rel, string $fallback): string
{
    $root = getenv('PHPC_DEPLOY_ROOT');
    if (false === $root || '' === $root) {
        return $fallback;
    }

    return '' === $rel ? $root : $root.'/'.$rel;
}
