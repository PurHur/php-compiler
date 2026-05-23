<?php

declare(strict_types=1);

/**
 * Minimal bin/compile.php driver closure for self-host bundle smoke (issue #212).
 *
 * Exercises parseAndCompile + Superglobals::populateFromEnvironment without vendor/src/cli.php.
 * Fully qualified names only — bundled via literal require_once (no use imports).
 */

function compile_driver_smoke(string $filename, string $code, array $options): string
{
    if ('-' !== $filename && str_contains(str_replace('\\', '/', $filename), '/test/selfhost/')) {
        $selfhostAot = getenv('PHP_COMPILER_SELFHOST_AOT');
        if (false === $selfhostAot || '' === $selfhostAot) {
            putenv('PHP_COMPILER_SELFHOST_AOT=1');
        }
    }
    $includes = $options['--include'] ?? [];
    if (!is_array($includes)) {
        $includes = [] === $includes || '' === $includes ? [] : [$includes];
    }
    /** @var list<string> $includes */
    if ([] === $includes && '-' !== $filename && is_file($filename)) {
        $runtime = new \PHPCompiler\Runtime(\PHPCompiler\Runtime::MODE_AOT);
        $includes = \PHPCompiler\Web\LiteralIncludeDiscovery::discoverDirectAbsolutePaths($runtime, $filename);
    }
    if ([] !== $includes) {
        $projectRoot = \PHPCompiler\Web\DeployRoot::findProjectRootForPath($filename);
        [$code, $filename] = \PHPCompiler\Web\SourceBundler::bundleForAot($filename, $includes, $projectRoot);
    }

    $runtime = new \PHPCompiler\Runtime(\PHPCompiler\Runtime::MODE_AOT);
    $queryString = $options['-q'] ?? null;
    if (!is_string($queryString) || '' === $queryString) {
        $fromEnv = getenv('QUERY_STRING');
        if (is_string($fromEnv) && '' !== $fromEnv) {
            $queryString = $fromEnv;
        }
    }
    $postBody = $options['-p'] ?? null;
    if (!is_string($postBody) || '' === $postBody) {
        $bodyEnv = getenv('REQUEST_BODY');
        if (is_string($bodyEnv) && '' !== $bodyEnv) {
            $postBody = $bodyEnv;
        }
    }
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
    if (null === $block) {
        return 'compile_driver_smoke parse FAIL';
    }

    return 'compile_driver_smoke parse OK';
}
