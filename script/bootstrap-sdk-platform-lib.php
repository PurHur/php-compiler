<?php

declare(strict_types=1);

/**
 * Bootstrap SDK platform contract helpers (#15606).
 *
 * Machine-readable SSOT: docs/bootstrap-sdk-platform.json
 * Human doc: docs/bootstrap-sdk-platform.md
 */

/**
 * @return array{
 *   issue: int,
 *   version: int,
 *   supported: array{
 *     os: string,
 *     arch: string,
 *     llvm_major: int,
 *     reference_image: string,
 *     ram_ci_gb: int,
 *     ram_docker_gb: int,
 *     glibc: bool
 *   },
 *   non_goals: list<string>,
 *   doc: string,
 *   related_docs: list<string>
 * }
 */
function bootstrap_sdk_platform_contract(string $root): array
{
    $path = $root.'/docs/bootstrap-sdk-platform.json';
    if (!is_readable($path)) {
        throw new RuntimeException("missing {$path}");
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('invalid docs/bootstrap-sdk-platform.json');
    }

    return $decoded;
}

/**
 * @return list<string>
 */
function bootstrap_sdk_platform_check(string $root): array
{
    $errors = [];
    try {
        $contract = bootstrap_sdk_platform_contract($root);
    } catch (RuntimeException $e) {
        return [$e->getMessage()];
    }

    $docRel = $contract['doc'] ?? '';
    $docPath = $root.'/'.$docRel;
    if (!is_readable($docPath)) {
        $errors[] = "missing human doc {$docRel}";
    }

    $supported = $contract['supported'] ?? null;
    if (!is_array($supported)) {
        $errors[] = 'contract.supported must be an object';
    } else {
        foreach (['os', 'arch', 'llvm_major', 'reference_image', 'ram_ci_gb', 'ram_docker_gb'] as $key) {
            if (!array_key_exists($key, $supported)) {
                $errors[] = "contract.supported missing {$key}";
            }
        }
        if (($supported['os'] ?? '') !== 'linux') {
            $errors[] = 'contract.supported.os must be linux';
        }
        if (($supported['arch'] ?? '') !== 'x86_64') {
            $errors[] = 'contract.supported.arch must be x86_64';
        }
        if ((int) ($supported['llvm_major'] ?? 0) !== 9) {
            $errors[] = 'contract.supported.llvm_major must be 9';
        }
    }

    $nonGoals = $contract['non_goals'] ?? null;
    if (!is_array($nonGoals) || [] === $nonGoals) {
        $errors[] = 'contract.non_goals must be a non-empty list';
    } elseif (!in_array('macos', $nonGoals, true)) {
        $errors[] = 'contract.non_goals must include macos (#15606 non-goal)';
    }

    if (is_readable($docPath)) {
        $doc = (string) file_get_contents($docPath);
        $requiredDocSnippets = [
            '#15606',
            'Linux',
            'x86_64',
            'LLVM 9',
            'macOS',
            'aarch64',
            'bootstrap-sdk-platform.json',
        ];
        foreach ($requiredDocSnippets as $snippet) {
            if (!str_contains($doc, $snippet)) {
                $errors[] = "docs/bootstrap-sdk-platform.md missing required snippet: {$snippet}";
            }
        }
    }

    foreach ($contract['related_docs'] ?? [] as $relatedRel) {
        $relatedPath = $root.'/'.$relatedRel;
        if (!is_readable($relatedPath)) {
            $errors[] = "missing related doc {$relatedRel}";
            continue;
        }
        $body = (string) file_get_contents($relatedPath);
        if (!str_contains($body, 'bootstrap-sdk-platform.md')) {
            $errors[] = "{$relatedRel} must link bootstrap-sdk-platform.md";
        }
    }

    $doctor = $root.'/lib/Doctor.php';
    if (is_readable($doctor)) {
        $body = (string) file_get_contents($doctor);
        if (!str_contains($body, 'bootstrap-sdk-platform.md')) {
            $errors[] = 'lib/Doctor.php must reference bootstrap-sdk-platform.md';
        }
    } else {
        $errors[] = 'missing lib/Doctor.php';
    }

    $pack = $root.'/script/bootstrap-sdk-pack.sh';
    if (is_readable($pack)) {
        $body = (string) file_get_contents($pack);
        if (!str_contains($body, 'linux-x86_64')) {
            $errors[] = 'script/bootstrap-sdk-pack.sh must encode linux-x86_64 asset name';
        }
    }

    return $errors;
}
