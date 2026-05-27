<?php

declare(strict_types=1);

/**
 * Shared M4 gen-2/gen-3 emit_path profile for docs ↔ bootstrap-loop scripts (issue #2115).
 */

/**
 * @return array{
 *   zend_fallback: bool,
 *   native_success: bool,
 *   gen2_strict_env: bool,
 *   link_compile_driver_env: bool,
 *   runtime_compile_env: bool,
 *   emit_path_tokens: list<string>
 * }
 */
function bootstrap_m4_gen2_script_profile(string $gen1LinkSource, string $probeSource): array
{
    $emitTokens = [];
    foreach (['emit_path=native', 'emit_path=zend partial', 'emit_path=zend', 'emit_path=zend_fallback_would_be_used'] as $token) {
        if (str_contains($gen1LinkSource, $token) || str_contains($probeSource, $token)) {
            $emitTokens[] = $token;
        }
    }

    return [
        'zend_fallback' => str_contains($gen1LinkSource, 'M4_EMIT_PATH="zend"')
            || str_contains($gen1LinkSource, 'OK emit_path=zend partial'),
        'native_success' => str_contains($gen1LinkSource, 'OK emit_path=native'),
        'gen2_strict_env' => str_contains($gen1LinkSource, 'BOOTSTRAP_M4_GEN2_STRICT'),
        'link_compile_driver_env' => str_contains($gen1LinkSource, 'BOOTSTRAP_M4_LINK_COMPILE_DRIVER'),
        'runtime_compile_env' => str_contains($gen1LinkSource, 'BOOTSTRAP_M4_RUNTIME_COMPILE'),
        'emit_path_tokens' => $emitTokens,
    ];
}

/**
 * @return array{gen3_spine_script: bool, gen3_spine_success_line: bool}
 */
function bootstrap_m4_gen3_script_profile(string $gen2RecompileSource): array
{
    return [
        'gen3_spine_script' => str_contains($gen2RecompileSource, 'bootstrap-loop-gen3-full-spine'),
        'gen3_spine_success_line' => str_contains($gen2RecompileSource, 'bootstrap-loop-gen2-recompile-spine: OK'),
    ];
}

/**
 * @param array{
 *   zend_fallback: bool,
 *   native_success: bool,
 *   gen2_strict_env: bool,
 *   link_compile_driver_env: bool,
 *   runtime_compile_env: bool,
 *   emit_path_tokens: list<string>
 * } $profile
 * @param array{gen3_spine_script: bool, gen3_spine_success_line: bool} $gen3Profile
 * @param list<string> $errors
 */
function bootstrap_m4_gen2_validate_doc(string $rel, string $doc, array $profile, array $gen3Profile, array &$errors): void
{
    if ($profile['gen2_strict_env'] && !str_contains($doc, 'BOOTSTRAP_M4_GEN2_STRICT')) {
        $errors[] = "{$rel}: missing BOOTSTRAP_M4_GEN2_STRICT gate name (see bootstrap-loop-gen1-link.sh)";
    }

    if ($profile['link_compile_driver_env'] && !str_contains($doc, 'BOOTSTRAP_M4_LINK_COMPILE_DRIVER')) {
        $errors[] = "{$rel}: missing BOOTSTRAP_M4_LINK_COMPILE_DRIVER env (see bootstrap-loop-gen1-link.sh / Makefile)";
    }

    if ($profile['zend_fallback']) {
        if (!preg_match('/gen-2.*Zend|Zend.*gen-2|gen-2 \*\*Zend\*\*|emit_path=zend partial/i', $doc)) {
            $errors[] = "{$rel}: M4 gen-2 must mention Zend partial emit while bootstrap-loop-gen1-link.sh retains Zend fallback";
        }
        if (preg_match('/native gen-2 emit\s*[✅]|native gen-2 emit\s*\|\s*✅/i', $doc)
            && !preg_match('/Zend (partial|fallback)|emit_path=zend partial/i', $doc)) {
            $errors[] = "{$rel}: claims native gen-2 emit complete while script still has Zend fallback path";
        }
    }

    if (!$profile['native_success'] && preg_match('/\bgen-2 native emit\b.*\b(✅|green|complete)\b/i', $doc)) {
        $errors[] = "{$rel}: stale native gen-2 complete claim (bootstrap-loop-gen1-link.sh has no OK emit_path=native yet)";
    }

    if ($profile['native_success'] && $profile['zend_fallback'] && !preg_match('/native gen-2|emit_path=native/i', $doc)) {
        $errors[] = "{$rel}: script supports native gen-2 emit_path=native — document opt-in path (BOOTSTRAP_M4_LINK_COMPILE_DRIVER)";
    }

    if ($gen3Profile['gen3_spine_script']) {
        if (!str_contains($doc, 'bootstrap-loop-gen2-recompile-spine')) {
            $errors[] = "{$rel}: missing bootstrap-loop-gen2-recompile-spine.sh reference (gen-2→gen-3 spine recompile)";
        }
        if (!preg_match('/gen-3|bootstrap-loop-gen3-full-spine/i', $doc)) {
            $errors[] = "{$rel}: must document gen-3 spine artifact (bootstrap-loop-gen3-full-spine)";
        }
    }

    if (preg_match('/gen-2.*compiles itself|gen-2→gen-3|gen-2 recompiles/i', $doc)
        && !$gen3Profile['gen3_spine_script']) {
        $errors[] = "{$rel}: claims gen-2 self-compile but bootstrap-loop-gen2-recompile-spine.sh missing gen-3 wiring";
    }

    foreach ($profile['emit_path_tokens'] as $token) {
        if (!str_contains($doc, $token) && 'emit_path=zend_fallback_would_be_used' !== $token) {
            if (in_array($token, ['emit_path=zend partial', 'emit_path=native'], true)) {
                continue;
            }
        }
    }

    if (!str_contains($doc, 'bootstrap-loop-gen1-link')) {
        $errors[] = "{$rel}: missing bootstrap-loop-gen1-link.sh reference for M4 gen-1 link";
    }
}
