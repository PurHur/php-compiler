<?php

declare(strict_types=1);

/**
 * Shared M3 compile-smoke strict emit_path profile for docs ↔ probe script (issue #2176).
 */

/**
 * @return array{
 *   zend_fallback: bool,
 *   native_success: bool,
 *   strict_env: bool,
 *   link_compile_driver_env: bool,
 *   runtime_compile_env: bool,
 *   emit_path_tokens: list<string>
 * }
 */
function bootstrap_m3_compile_smoke_script_profile(string $probeSource): array
{
    $emitTokens = [];
    foreach (['emit_path=native', 'emit_path=zend partial', 'emit_path=zend', 'emit_path=zend_fallback_would_be_used'] as $token) {
        if (str_contains($probeSource, $token)) {
            $emitTokens[] = $token;
        }
    }

    return [
        'zend_fallback' => str_contains($probeSource, 'M3_EMIT_PATH="zend"')
            || str_contains($probeSource, 'OK emit_path=zend partial'),
        'native_success' => str_contains($probeSource, 'OK emit_path=native'),
        'strict_env' => str_contains($probeSource, 'BOOTSTRAP_M3_COMPILE_SMOKE_STRICT'),
        'link_compile_driver_env' => str_contains($probeSource, 'BOOTSTRAP_M3_LINK_COMPILE_DRIVER'),
        'runtime_compile_env' => str_contains($probeSource, 'BOOTSTRAP_M3_RUNTIME_COMPILE'),
        'emit_path_tokens' => $emitTokens,
    ];
}

/**
 * @param array{
 *   zend_fallback: bool,
 *   native_success: bool,
 *   strict_env: bool,
 *   link_compile_driver_env: bool,
 *   runtime_compile_env: bool,
 *   emit_path_tokens: list<string>
 * } $profile
 * @param list<string> $errors
 */
function bootstrap_m3_strict_validate_doc(string $rel, string $doc, array $profile, array &$errors): void
{
    if (!str_contains($doc, 'bootstrap-selfhost-compile-smoke-probe')) {
        $errors[] = "{$rel}: missing bootstrap-selfhost-compile-smoke-probe.sh reference for M3 compile-smoke";
    }

    if ($profile['strict_env'] && !str_contains($doc, 'BOOTSTRAP_M3_COMPILE_SMOKE_STRICT')) {
        $errors[] = "{$rel}: missing BOOTSTRAP_M3_COMPILE_SMOKE_STRICT gate name (see bootstrap-selfhost-compile-smoke-probe.sh)";
    }

    if ($profile['link_compile_driver_env'] && !str_contains($doc, 'BOOTSTRAP_M3_LINK_COMPILE_DRIVER')) {
        $errors[] = "{$rel}: missing BOOTSTRAP_M3_LINK_COMPILE_DRIVER env (see compile-smoke probe / Makefile)";
    }

    if ($profile['zend_fallback']) {
        if (!preg_match('/compile-smoke.*Zend|Zend.*compile-smoke|emit_path=zend/i', $doc)) {
            $errors[] = "{$rel}: M3 compile-smoke must mention Zend partial emit while probe retains Zend fallback";
        }
        if (preg_match('/compile-smoke native emit\s*[✅]|strict native emit\s*[✅]/i', $doc)) {
            $errors[] = "{$rel}: claims compile-smoke native emit complete while probe still has Zend fallback path";
        }
    }

    if (!$profile['native_success'] && preg_match('/\bcompile-smoke native emit\b.*\b(✅|green|complete)\b/i', $doc)) {
        $errors[] = "{$rel}: stale compile-smoke native emit complete claim (probe has no OK emit_path=native yet)";
    }

    if ($profile['native_success'] && $profile['zend_fallback'] && !preg_match('/emit_path=native|native emit/i', $doc)) {
        $errors[] = "{$rel}: probe supports native emit_path=native — document opt-in path (BOOTSTRAP_M3_LINK_COMPILE_DRIVER)";
    }
}

function ci_defaults_gate_default(string $repoRoot, string $gate): string
{
    $path = $repoRoot.'/script/ci-defaults.env';
    if (!is_readable($path)) {
        return '1';
    }
    $envBody = (string) file_get_contents($path);
    $pattern = '/export\s+'.preg_quote($gate, '/').'="\$\{'
        .preg_quote($gate, '/').':-([01])\}"/';
    if (preg_match($pattern, $envBody, $m)) {
        return $m[1];
    }

    return '1';
}

/**
 * @param list<string> $errors
 */
function bootstrap_m3_strict_validate_local_ci_matrix(string $matrixDoc, array &$errors): void
{
    $gates = [
        'BOOTSTRAP_M3_COMPILE_SMOKE_PROBE_GATE' => ci_defaults_gate_default(dirname(__DIR__), 'BOOTSTRAP_M3_COMPILE_SMOKE_PROBE_GATE'),
        'BOOTSTRAP_M3_COMPILE_SMOKE_STRICT_GATE' => ci_defaults_gate_default(dirname(__DIR__), 'BOOTSTRAP_M3_COMPILE_SMOKE_STRICT_GATE'),
        'BOOTSTRAP_M3_STRICT_SYNC_GATE' => ci_defaults_gate_default(dirname(__DIR__), 'BOOTSTRAP_M3_STRICT_SYNC_GATE'),
    ];

    foreach ($gates as $gate => $default) {
        if (!str_contains($matrixDoc, $gate)) {
            $errors[] = "local-ci-matrix.md: missing {$gate} row (sync ci-defaults.env default `{$default}`)";
            continue;
        }
        if (!preg_match('/\| `'.preg_quote($gate, '/').'` \| `'.$default.'` \|/', $matrixDoc)) {
            $errors[] = "local-ci-matrix.md: {$gate} default must be `{$default}` (match ci-defaults.env)";
        }
    }

    if (!str_contains($matrixDoc, 'check-bootstrap-m3-strict-sync.php')) {
        $errors[] = 'local-ci-matrix.md: missing check-bootstrap-m3-strict-sync.php reference (#2176)';
    }
}

/**
 * @param list<string> $errors
 */
function bootstrap_m3_strict_validate_development_status(string $statusDoc, array &$errors): void
{
    $probeDefault = ci_defaults_gate_default(dirname(__DIR__), 'BOOTSTRAP_M3_COMPILE_SMOKE_PROBE_GATE');
    $strictDefault = ci_defaults_gate_default(dirname(__DIR__), 'BOOTSTRAP_M3_COMPILE_SMOKE_STRICT_GATE');

    if ('1' === $probeDefault && !str_contains($statusDoc, 'BOOTSTRAP_M3_COMPILE_SMOKE_PROBE_GATE')) {
        $errors[] = 'development-status.md: missing BOOTSTRAP_M3_COMPILE_SMOKE_PROBE_GATE (NS2 M3 ladder)';
    }

    if ('0' === $strictDefault && !str_contains($statusDoc, 'BOOTSTRAP_M3_COMPILE_SMOKE_STRICT_GATE')
        && !preg_match('/strict native emit\s*🚧|compile-smoke.*🚧/i', $statusDoc)) {
        $errors[] = 'development-status.md: missing strict native emit 🚧 or BOOTSTRAP_M3_COMPILE_SMOKE_STRICT_GATE (NS2 M3 row)';
    }

    if (!str_contains($statusDoc, '#1937')) {
        $errors[] = 'development-status.md: missing compile-smoke #1937 reference (NS2 M3 row)';
    }

    if ('1' === $probeDefault && !preg_match('/BOOTSTRAP_M3_COMPILE_SMOKE_PROBE_GATE=1/', $statusDoc)) {
        $errors[] = 'development-status.md: BOOTSTRAP_M3_COMPILE_SMOKE_PROBE_GATE=1 expected (ci-defaults default-on)';
    }
}
