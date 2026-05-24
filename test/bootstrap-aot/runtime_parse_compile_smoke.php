<?php

declare(strict_types=1);

/**
 * M3 spine slice: Runtime::parse + Runtime::compile (no parseAndCompile wrapper, no standalone).
 *
 * Issue #1496 — real-lowered on compile-driver spine when PHP_COMPILER_M3_COMPILE_DRIVER=1.
 *
 * Lint: php bin/compile.php -l test/bootstrap-aot/runtime_parse_compile_smoke.php
 */

require_once __DIR__.'/runtime_ctor_smoke.php';

/**
 * @return array{ok: bool, message: string, phase: string}
 */
function runtime_parse_compile_smoke(string $source = '<?php echo "parse compile smoke";'): array
{
    $ctor = runtime_ctor_smoke();
    if (!$ctor['ok']) {
        return $ctor;
    }

    $runtime = new \PHPCompiler\Runtime(\PHPCompiler\Runtime::MODE_AOT);
    $script = $runtime->parse($source, 'runtime_parse_compile_smoke.php');
    if (!($script instanceof \PHPCfg\Script)) {
        return [
            'ok' => false,
            'message' => 'runtime_parse_compile_smoke: parse did not return Script',
            'phase' => 'parse',
        ];
    }

    $block = $runtime->compile($script);
    if (null === $block) {
        return [
            'ok' => false,
            'message' => 'runtime_parse_compile_smoke: compile returned null',
            'phase' => 'compile',
        ];
    }

    return [
        'ok' => true,
        'message' => 'runtime_parse_compile_smoke: parse + compile OK',
        'phase' => 'compile',
    ];
}
