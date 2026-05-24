<?php

declare(strict_types=1);

namespace PHPCompiler\BootstrapAot;

/**
 * M3 spine slice: Runtime MODE_AOT ctor only (no parseAndCompile / standalone / loadJit).
 *
 * Lint: php bin/compile.php -l test/bootstrap-aot/runtime_ctor_smoke.php
 * Used by helloworld_compile_smoke to surface ctor failures before the full emit chain.
 */

/**
 * @return array{ok: bool, message: string, phase: string}
 */
function runtime_ctor_smoke(): array
{
    if (\function_exists('putenv')) {
        putenv('PHP_COMPILER_SELFHOST_AOT');
    }

    $runtime = new \PHPCompiler\Runtime(\PHPCompiler\Runtime::MODE_AOT);
    if (!isset($runtime->compiler, $runtime->vmContext)) {
        return [
            'ok' => false,
            'message' => 'runtime_ctor_smoke: MODE_AOT ctor incomplete (compiler/vmContext spine)',
            'phase' => 'ctor',
        ];
    }

    return [
        'ok' => true,
        'message' => 'runtime_ctor_smoke: MODE_AOT ctor OK',
        'phase' => 'ctor',
    ];
}
