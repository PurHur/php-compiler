<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

/**
 * Validates phpc.json structure and on-disk paths (issue #263).
 */
final class ManifestValidator
{
    /** @var list<string> */
    private const TOP_LEVEL_KEYS = [
        'binary',
        'public',
        'assets',
        'entry',
        'index',
        'includes',
        'autoload',
    ];

    /**
     * Pre-build validation: phpc.json + entry exist; binary path is declared but need not exist yet.
     *
     * @return list<string> actionable error messages (empty when valid)
     */
    public static function validateForBuild(string $projectDir): array
    {
        $dir = realpath($projectDir);
        if (false === $dir || !is_dir($dir)) {
            return ['project directory not found: '.$projectDir];
        }

        $manifestPath = $dir.'/phpc.json';
        if (!is_file($manifestPath)) {
            return ['phpc.json not found in '.$dir];
        }

        $raw = file_get_contents($manifestPath);
        if (false === $raw) {
            return ['cannot read phpc.json'];
        }

        $data = json_decode($raw, true);
        if (JSON_ERROR_NONE !== json_last_error()) {
            return ['phpc.json is not valid JSON: '.json_last_error_msg()];
        }
        if (!is_array($data)) {
            return ['phpc.json root must be a JSON object'];
        }

        $errors = [];
        foreach (array_keys($data) as $key) {
            if (!is_string($key) || !in_array($key, self::TOP_LEVEL_KEYS, true)) {
                $errors[] = 'unknown key in phpc.json: '.$key;
            }
        }

        if (!isset($data['entry']) || !is_string($data['entry']) || '' === $data['entry']) {
            $errors[] = 'missing required key: entry';
        } else {
            $entryPath = ProjectManifest::resolveRelativePath($dir, $data['entry']);
            if (!is_file($entryPath)) {
                $errors[] = 'entry path not found: '.$data['entry'];
            }
        }

        if (!isset($data['binary']) || !is_string($data['binary']) || '' === $data['binary']) {
            $errors[] = 'missing required key: binary';
        }

        if (isset($data['includes'])) {
            $errors = array_merge($errors, self::validateIncludesOnDisk($dir, $data['includes']));
        }

        if (isset($data['assets'])) {
            $errors = array_merge($errors, self::validateAssetsOnDisk($dir, $data['assets']));
        }

        if (isset($data['autoload'])) {
            $errors = array_merge($errors, self::validateAutoload($data['autoload']));
            $errors = array_merge($errors, ProjectAutoload::validatePsr4PathsOnDisk($dir, $data['autoload']));
        }

        return $errors;
    }

    /**
     * @return list<string> actionable error messages (empty when valid)
     */
    public static function validate(string $projectDir): array
    {
        $dir = realpath($projectDir);
        if (false === $dir || !is_dir($dir)) {
            return ['project directory not found: '.$projectDir];
        }

        $manifestPath = $dir.'/phpc.json';
        if (!is_file($manifestPath)) {
            return ['phpc.json not found in '.$dir];
        }

        $raw = file_get_contents($manifestPath);
        if (false === $raw) {
            return ['cannot read phpc.json'];
        }

        $data = json_decode($raw, true);
        if (JSON_ERROR_NONE !== json_last_error()) {
            return ['phpc.json is not valid JSON: '.json_last_error_msg()];
        }
        if (!is_array($data)) {
            return ['phpc.json root must be a JSON object'];
        }

        $errors = [];
        foreach (array_keys($data) as $key) {
            if (!is_string($key) || !in_array($key, self::TOP_LEVEL_KEYS, true)) {
                $errors[] = 'unknown key in phpc.json: '.$key;
            }
        }

        if (!isset($data['binary'])) {
            $errors[] = 'missing required key: binary';
        } elseif (!is_string($data['binary']) || '' === $data['binary']) {
            $errors[] = 'binary must be a non-empty string';
        } else {
            $binaryPath = ProjectManifest::resolveRelativePath($dir, $data['binary']);
            if (!is_file($binaryPath)) {
                $errors[] = 'binary path not found: '.$data['binary'];
            }
        }

        if (isset($data['public'])) {
            if (!is_string($data['public']) || '' === $data['public']) {
                $errors[] = 'public must be a non-empty string';
            } else {
                $publicDir = ProjectManifest::resolveRelativePath($dir, $data['public']);
                if (!is_dir($publicDir)) {
                    $errors[] = 'public directory not found: '.$data['public'];
                } else {
                    $index = $publicDir.'/index.php';
                    if (!is_file($index)) {
                        $errors[] = 'missing public/index.php under '.$data['public'];
                    }
                }
            }
        }

        if (isset($data['entry'])) {
            if (!is_string($data['entry']) || '' === $data['entry']) {
                $errors[] = 'entry must be a non-empty string';
            } else {
                $entryPath = ProjectManifest::resolveRelativePath($dir, $data['entry']);
                if (!is_file($entryPath)) {
                    $errors[] = 'entry path not found: '.$data['entry'];
                }
            }
        }

        if (isset($data['index'])) {
            if (!is_string($data['index']) || '' === $data['index']) {
                $errors[] = 'index must be a non-empty string';
            } else {
                $indexPath = ProjectManifest::resolveRelativePath($dir, $data['index']);
                if (!is_file($indexPath)) {
                    $errors[] = 'index path not found: '.$data['index'];
                }
            }
        }

        if (isset($data['includes'])) {
            $errors = array_merge($errors, self::validateIncludesOnDisk($dir, $data['includes']));
        }

        if (isset($data['assets'])) {
            $errors = array_merge($errors, self::validateAssetsOnDisk($dir, $data['assets']));
        }

        if (isset($data['autoload'])) {
            $errors = array_merge($errors, self::validateAutoload($data['autoload']));
            $errors = array_merge($errors, ProjectAutoload::validatePsr4PathsOnDisk($dir, $data['autoload']));
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function validateAssetsOnDisk(string $projectDir, mixed $assets): array
    {
        if (!is_string($assets) || '' === $assets) {
            return ['assets must be a non-empty string'];
        }

        $assetsDir = ProjectManifest::resolveRelativePath($projectDir, $assets);
        if (!is_dir($assetsDir)) {
            return ['assets directory not found: '.$assets];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private static function validateIncludesOnDisk(string $projectDir, mixed $includes): array
    {
        if (!is_array($includes)) {
            return ['includes must be an array of strings'];
        }

        $errors = [];
        foreach ($includes as $i => $item) {
            if (!is_string($item) || '' === $item) {
                $errors[] = 'includes['.$i.'] must be a non-empty string';
                continue;
            }
            $includePath = ProjectManifest::resolveRelativePath($projectDir, $item);
            if (!is_file($includePath)) {
                $errors[] = 'includes path not found: '.$item;
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function validateAutoload(mixed $autoload): array
    {
        if (!is_array($autoload)) {
            return ['autoload must be an object'];
        }

        $errors = [];
        foreach (array_keys($autoload) as $key) {
            if (!is_string($key) || 'psr-4' !== $key) {
                $errors[] = 'unknown key in autoload: '.(is_string($key) ? $key : '(invalid)');
            }
        }

        if (!isset($autoload['psr-4'])) {
            return $errors;
        }

        $psr4 = $autoload['psr-4'];
        if (!is_array($psr4)) {
            $errors[] = 'autoload.psr-4 must be an object';

            return $errors;
        }

        foreach ($psr4 as $prefix => $path) {
            if (!is_string($prefix) || '' === $prefix) {
                $errors[] = 'autoload.psr-4 keys must be non-empty namespace prefixes';
            }
            if (!is_string($path) || '' === $path) {
                $errors[] = 'autoload.psr-4 values must be non-empty paths';
            }
        }

        return $errors;
    }
}
