<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPCompiler\Config;

/**
 * Output-path / vendor-object helpers extracted from Linker (#36403 size budget).
 */
final class LinkerOutputArtifacts
{
    /**
     * Repo-relative paths to committed M5 vendor prelink objects (issue #1416).
     *
     * @return list<string>
     */
    public static function prelinkedVendorObjectPaths(string $projectRoot): array
    {
        $manifest = $projectRoot.'/prelinked/bootstrap-vendor/manifest.json';
        if (!is_file($manifest)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($manifest), true);
        if (!is_array($data) || !isset($data['packages']) || !is_array($data['packages'])) {
            return [];
        }
        $paths = [];
        foreach ($data['packages'] as $info) {
            if (!is_array($info)) {
                continue;
            }
            $rel = $info['object'] ?? '';
            if (!is_string($rel) || '' === $rel) {
                continue;
            }
            $abs = $projectRoot.'/'.$rel;
            if (is_file($abs) && ($info['status'] ?? '') === 'object_ok') {
                $paths[] = $rel;
            }
        }

        return $paths;
    }

    /**
     * Resolve the on-disk artifact path after compileToFile() for the requested -o value (#8709).
     */
    public static function resolveEffectiveOutputPath(string $requestedOut): string
    {
        $keepObject = Config::getenv('PHP_COMPILER_KEEP_OBJECT_FILE');
        $vendorPrelink = Config::getenv('PHP_COMPILER_VENDOR_PRELINK');
        $selfhostAot = Config::getenv('PHP_COMPILER_SELFHOST_AOT');
        $vendorObjectOnly = ('1' === $vendorPrelink || 'true' === strtolower((string) $vendorPrelink))
            && ('0' === $selfhostAot || 'false' === strtolower((string) $selfhostAot));
        $keepingObjectOnly = ('1' === $keepObject || 'true' === strtolower((string) $keepObject))
            || $vendorObjectOnly;

        if ($keepingObjectOnly && !str_ends_with($requestedOut, '.o')) {
            return $requestedOut.'.o';
        }

        return $requestedOut;
    }

    /**
     * Inventory argv emit must materialize a non-empty regular file (#3046, #8709).
     *
     * @throws \LogicException when missing, not a regular file, or zero bytes
     */
    public static function assertNonEmptyOutputFile(string $path): void
    {
        if (!is_file($path)) {
            throw new \LogicException(sprintf(
                'compile driver: output file missing after emit: %s (#8709)',
                $path
            ));
        }
        $size = filesize($path);
        if (false === $size || $size <= 0) {
            throw new \LogicException(sprintf(
                'compile driver: output file is empty: %s (#8709)',
                $path
            ));
        }
    }

    /**
     * Verify the -o artifact referenced by bin/compile.php argv drivers (#8709).
     *
     * @throws \LogicException
     */
    public static function assertNonEmptyRequestedOutput(string $requestedOut): void
    {
        self::assertNonEmptyOutputFile(self::resolveEffectiveOutputPath($requestedOut));
    }
}
