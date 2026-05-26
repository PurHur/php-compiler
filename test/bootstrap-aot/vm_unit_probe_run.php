<?php

declare(strict_types=1);

/**
 * VM unit probe run closure (issue #2354).
 *
 * Spine-safe: no `use` imports — bundled alongside lib/Runtime.php for self-host AOT.
 */

function vm_unit_probe_run(string $filename, string $code, array $options): string
{
    $runtime = new \PHPCompiler\Runtime();
    $queryString = $options['-q'] ?? null;
    $postBody = $options['-p'] ?? null;
    $scriptFilename = null;
    if ('-' !== $filename && 'Command line code' !== $filename) {
        $resolved = realpath($filename);
        if (false !== $resolved) {
            $scriptFilename = $resolved;
        }
    }
    \PHPCompiler\Web\Superglobals::populateFromEnvironment(
        $runtime->vmContext,
        is_string($queryString) ? $queryString : null,
        is_string($postBody) ? $postBody : null,
        $scriptFilename
    );
    $block = $runtime->parseAndCompile($code, $filename);
    if (!isset($options['-l'])) {
        try {
            $runtime->run($block);
        } catch (\PHPCompiler\VM\ScriptExit $e) {
            return 'vm_unit_probe_run exit '.$e->status;
        }
    }

    return 'vm_unit_probe_run OK';
}
