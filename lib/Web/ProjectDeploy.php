<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

/**
 * Package an AOT project into a production-shaped dist/ tree (issue #609).
 */
final class ProjectDeploy
{
    public const README_DEPLOY = 'README.deploy';

    /**
     * @return list<string> error messages; empty on success
     */
    public static function deploy(string $projectDir, string $outputDir, bool $buildIfMissing = true): array
    {
        $projectReal = realpath($projectDir);
        if (false === $projectReal || !is_dir($projectReal)) {
            return ['project directory not found: '.$projectDir];
        }

        $manifest = ProjectManifest::loadManifest($projectReal);
        if (null === $manifest) {
            return ['phpc.json not found under: '.$projectDir];
        }

        $errors = ManifestValidator::validate($projectReal);
        if ([] !== $errors) {
            return $errors;
        }

        $binarySrc = ProjectManifest::resolveBinaryOutputPath($projectReal, $manifest);
        if (null === $binarySrc) {
            return ['phpc.json missing a valid "binary" path'];
        }

        if (!is_file($binarySrc)) {
            if (!$buildIfMissing) {
                return ['AOT binary not found (build first or omit --from-build): '.$binarySrc];
            }

            return ['AOT binary not found; run: phpc build --project '.escapeshellarg($projectReal)];
        }

        $outReal = self::prepareOutputDir($outputDir);
        if (null === $outReal) {
            return ['cannot create output directory: '.$outputDir];
        }

        $binDir = $outReal.'/bin';
        if (!is_dir($binDir) && !mkdir($binDir, 0777, true) && !is_dir($binDir)) {
            return ['cannot create bin directory: '.$binDir];
        }

        $binDest = $binDir.'/app';
        if (!copy($binarySrc, $binDest)) {
            return ['failed to copy binary to '.$binDest];
        }
        @chmod($binDest, 0755);

        $manifestDest = $outReal.'/phpc.json';
        $manifestSrc = $projectReal.'/phpc.json';
        if (!copy($manifestSrc, $manifestDest)) {
            return ['failed to copy phpc.json'];
        }

        if (isset($manifest['public']) && is_string($manifest['public']) && '' !== $manifest['public']) {
            $publicSrc = self::resolveTreeUnderProject($projectReal, $manifest['public']);
            if (null === $publicSrc) {
                return ['public directory escapes project root or is missing: '.$manifest['public']];
            }
            if (!self::copyDirectory($publicSrc, $outReal.'/public')) {
                return ['failed to copy public/ tree'];
            }
        }

        if (isset($manifest['assets']) && is_string($manifest['assets']) && '' !== $manifest['assets']) {
            $assetsSrc = self::resolveTreeUnderProject($projectReal, $manifest['assets']);
            if (null === $assetsSrc) {
                return ['assets directory escapes project root or is missing: '.$manifest['assets']];
            }
            if (!self::copyDirectory($assetsSrc, $outReal.'/assets')) {
                return ['failed to copy assets/ tree'];
            }
        }

        $templatesSrc = $projectReal.'/templates';
        if (is_dir($templatesSrc)) {
            $templatesReal = realpath($templatesSrc);
            if (false === $templatesReal || !self::pathIsUnderRoot($templatesReal, $projectReal)) {
                return ['templates directory escapes project root'];
            }
            if (!self::copyDirectory($templatesReal, $outReal.'/templates')) {
                return ['failed to copy templates/ tree'];
            }
        }

        $wrapperSrc = dirname(__DIR__, 2).'/bin/cgi-aot.sh';
        if (!is_file($wrapperSrc)) {
            return ['cgi-aot.sh missing in repository (issue #665)'];
        }
        $wrapperDest = $outReal.'/'.CgiAotDriver::WRAPPER_NAME;
        if (!copy($wrapperSrc, $wrapperDest)) {
            return ['failed to copy '.CgiAotDriver::WRAPPER_NAME];
        }
        @chmod($wrapperDest, 0755);

        $readme = self::readmeDeployContent();
        if (false === file_put_contents($outReal.'/'.self::README_DEPLOY, $readme)) {
            return ['failed to write '.self::README_DEPLOY];
        }

        return [];
    }

    public static function readmeDeployContent(): string
    {
        return <<<'TXT'
php-compiler deploy bundle
==========================

Run the AOT binary behind nginx/CGI/FastCGI with the document root set to public/
(when present). Set environment variables as needed:

  PHPC_DEPLOY_ROOT   Absolute path to this dist directory (required for
                     phpc_deploy_path() template/includes rewritten at AOT link time)
  QUERY_STRING       CGI query string (e.g. name=value&page=1)
  PHP_COMPILER_SESSION_DIR  Writable directory for file-backed $_SESSION (issue #1881)
  PHP_COMPILER_DEBUG Set to 1 for serve/build diagnostics

Example (direct binary):

  export PHPC_DEPLOY_ROOT=/var/www/myapp
  ./bin/app

Production CGI (nginx ScriptAlias → cgi-wrapper):

  export PHPC_DEPLOY_ROOT=/var/www/myapp
  ./cgi-wrapper

See docs/deploy-web-aot.md (quickstart), docs/local-ci-matrix.md, and the production deployment guide (issue #445).

TXT;
    }

    private static function prepareOutputDir(string $outputDir): ?string
    {
        if (is_file($outputDir)) {
            return null;
        }
        if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
            return null;
        }

        $real = realpath($outputDir);

        return false !== $real && is_dir($real) ? $real : null;
    }

    /**
     * Resolve a manifest-relative directory and ensure it stays under the project root.
     */
    public static function resolveTreeUnderProject(string $projectRoot, string $relative): ?string
    {
        $projectReal = realpath($projectRoot);
        if (false === $projectReal) {
            return null;
        }

        $candidate = ProjectManifest::resolveRelativePath($projectReal, $relative);
        if (str_contains($relative, '..')) {
            return null;
        }

        $dirReal = realpath($candidate);
        if (false === $dirReal || !is_dir($dirReal)) {
            return null;
        }

        if (!self::pathIsUnderRoot($dirReal, $projectReal)) {
            return null;
        }

        return $dirReal;
    }

    public static function pathIsUnderRoot(string $path, string $root): bool
    {
        $pathReal = realpath($path);
        $rootReal = realpath($root);
        if (false === $pathReal || false === $rootReal) {
            return false;
        }

        $prefix = $rootReal.DIRECTORY_SEPARATOR;
        if ($pathReal === $rootReal) {
            return true;
        }

        return str_starts_with($pathReal, $prefix);
    }

    public static function copyDirectory(string $source, string $destination): bool
    {
        $sourceReal = realpath($source);
        if (false === $sourceReal || !is_dir($sourceReal)) {
            return false;
        }

        if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
            return false;
        }

        $destReal = realpath($destination);
        if (false === $destReal) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceReal, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen($sourceReal) + 1);
            if (str_contains($relative, '..')) {
                return false;
            }

            $target = $destReal.DIRECTORY_SEPARATOR.$relative;
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0777, true) && !is_dir($target)) {
                    return false;
                }
                continue;
            }

            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
                return false;
            }
            if (!copy($item->getPathname(), $target)) {
                return false;
            }
        }

        return true;
    }
}
