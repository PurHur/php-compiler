<?php

declare(strict_types=1);

/**
 * Minimal bin/vm.php run() closure for self-host bundle smoke (issue #1423).
 *
 * Full argv driver lives in bin/vm.php + src/cli.php (M4 bundle follow-up).
 */

use PHPCompiler\Runtime;
use PHPCompiler\Web\Superglobals;

function vm_run_smoke(string $filename, string $code, array $options): string
{
    $runtime = new Runtime();
    $queryString = $options['-q'] ?? null;
    $postBody = $options['-p'] ?? null;
    $scriptFilename = null;
    if ('-' !== $filename && 'Command line code' !== $filename) {
        $resolved = realpath($filename);
        if (false !== $resolved) {
            $scriptFilename = $resolved;
        }
    }
    Superglobals::populateFromEnvironment(
        $runtime->vmContext,
        is_string($queryString) ? $queryString : null,
        is_string($postBody) ? $postBody : null,
        $scriptFilename
    );
    $block = $runtime->parseAndCompile($code, $filename);
    if (!isset($options['-l'])) {
        try {
            $runtime->run($block);
        } catch (PHPCompiler\VM\ScriptExit $e) {
            return 'vm_run_smoke exit '.$e->status;
        }
    }

    return 'vm_run_smoke OK';
}
