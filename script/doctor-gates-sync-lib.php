<?php

declare(strict_types=1);

/**
 * Shared helpers for doctor ↔ ci-defaults gate drift guard (issue #2380).
 */

const DOCTOR_GATES_SYNC_DEFAULTS_REL = 'script/ci-defaults.env';
const DOCTOR_GATES_SYNC_ALLOWLIST_REL = 'docs/doctor-gates-allowlist.txt';
const DOCTOR_GATES_SYNC_DOCTOR_REL = 'lib/Doctor.php';
const DOCTOR_GATES_SYNC_MINIWEBAPP_GATES_REL = 'script/miniwebapp-gates.sh';
const DOCTOR_GATES_SYNC_MATRIX_REL = 'docs/local-ci-matrix.md';

/**
 * Gate names in ci-defaults.env that the doctor/matrix drift guard tracks (v1 patterns).
 */
function doctor_gates_sync_tracked_gate(string $name): bool
{
    if (!str_ends_with($name, '_GATE')) {
        return false;
    }
    if (preg_match('/_SMOKE_GATE$/', $name)) {
        return true;
    }
    if (preg_match('/_SYNC_GATE$/', $name)) {
        return true;
    }
    if (str_starts_with($name, 'BOOTSTRAP_')) {
        return true;
    }
    if (str_starts_with($name, 'MINIWEBAPP_')) {
        return true;
    }
    if (str_starts_with($name, 'THROWSWEB_') || str_starts_with($name, 'THROWS_WEB_')) {
        return true;
    }
    if (str_starts_with($name, 'FASTCGI_')) {
        return true;
    }
    if (str_contains($name, 'SERVE') && str_ends_with($name, '_GATE')) {
        return true;
    }

    return false;
}

/**
 * @return list<string>
 */
function doctor_gates_sync_parse_ci_defaults_gates(string $defaultsPath): array
{
    if (!is_readable($defaultsPath)) {
        throw new RuntimeException("missing {$defaultsPath}");
    }

    $text = (string) file_get_contents($defaultsPath);
    if (!preg_match_all('/^export ([A-Z0-9_]+_GATE)=/m', $text, $matches)) {
        return [];
    }

    $gates = [];
    foreach ($matches[1] as $name) {
        if (!doctor_gates_sync_tracked_gate($name)) {
            continue;
        }
        $gates[$name] = true;
    }

    $out = array_keys($gates);
    sort($out);

    return $out;
}

/**
 * @return array<string, true>
 */
function doctor_gates_sync_read_allowlist(string $allowlistPath): array
{
    if (!is_readable($allowlistPath)) {
        return [];
    }

    $allow = [];
    foreach (file($allowlistPath, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ('' === $line || str_starts_with($line, '#')) {
            continue;
        }
        $allow[$line] = true;
    }

    return $allow;
}

function doctor_gates_sync_gate_documented(string $gate, string $haystack): bool
{
    return str_contains($haystack, $gate);
}

/**
 * @param list<string> $gates
 * @return list<string> error messages
 */
function doctor_gates_sync_missing_gate_errors(
    string $root,
    array $gates,
    ?string $doctorBody = null,
    ?string $miniwebappGatesBody = null,
    ?string $matrixBody = null,
    ?array $allowlist = null,
): array {
    $doctorBody ??= (string) file_get_contents($root.'/'.DOCTOR_GATES_SYNC_DOCTOR_REL);
    $miniwebappGatesBody ??= (string) file_get_contents($root.'/'.DOCTOR_GATES_SYNC_MINIWEBAPP_GATES_REL);
    $matrixBody ??= (string) file_get_contents($root.'/'.DOCTOR_GATES_SYNC_MATRIX_REL);
    $allowlist ??= doctor_gates_sync_read_allowlist($root.'/'.DOCTOR_GATES_SYNC_ALLOWLIST_REL);

    $haystack = $doctorBody.$miniwebappGatesBody.$matrixBody;
    $errors = [];

    foreach ($gates as $gate) {
        if (isset($allowlist[$gate])) {
            continue;
        }
        if (!doctor_gates_sync_gate_documented($gate, $haystack)) {
            $errors[] = "ci-defaults gate {$gate} missing from lib/Doctor.php, script/miniwebapp-gates.sh, and docs/local-ci-matrix.md (or docs/doctor-gates-allowlist.txt)";
        }
    }

    return $errors;
}

/**
 * @return array{checked: int, missing: list<string>}
 */
function doctor_gates_sync_run(string $root): array
{
    $gates = doctor_gates_sync_parse_ci_defaults_gates($root.'/'.DOCTOR_GATES_SYNC_DEFAULTS_REL);
    $missing = doctor_gates_sync_missing_gate_errors($root, $gates);

    return [
        'checked' => count($gates),
        'missing' => $missing,
    ];
}
