<?php

declare(strict_types=1);

/**
 * Minimal bin/compile.php driver closure for self-host bundle smoke (issue #212).
 *
 * Standalone bootstrap-aot-link exercises a user-class method-call slice (#58).
 * Full parseAndCompile + Superglobals path lives in the selfhost bundle
 * (test/selfhost/compiler_driver_smoke/main.php requires this file).
 */

class CompileDriverSmokeRuntime
{
    public function parse(string $code, string $filename): string
    {
        if ('' === $code) {
            return 'compile_driver_smoke parse FAIL';
        }

        return 'compile_driver_smoke parse OK';
    }
}

function compile_driver_smoke(string $filename, string $code, array $options): string
{
    $runtime = new CompileDriverSmokeRuntime();

    return $runtime->parse($code, $filename);
}

echo compile_driver_smoke('smoke.php', '<?php', []), "\n";
