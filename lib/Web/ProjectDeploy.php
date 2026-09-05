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

    /** Dist-relative paths written by {@see writeOciBundle} (#36392). */
    public const OCI_DOCKERFILE = 'Dockerfile';

    public const OCI_DOCKERFILE_FCGI = 'Dockerfile.fcgi';

    public const OCI_COMPOSE = 'docker-compose.fcgi.yml';

    public const OCI_NGINX = 'nginx/fastcgi.conf';

    public const OCI_README = 'README.oci';

    /**
     * Emit an OCI/nginx context into an existing deploy dist (#36392).
     *
     * Scratch image = AOT binary + trees (CGI one-shot ENTRYPOINT). FastCGI on
     * :9000 needs a PHP-capable companion (`phpc fcgi --binary`) — that is the
     * Dockerfile.fcgi / compose file, not FROM scratch (the AOT app does not
     * speak FastCGI framing by itself).
     *
     * @return list<string> error messages; empty on success
     */
    public static function writeOciBundle(string $distDir): array
    {
        $distReal = realpath($distDir);
        if (false === $distReal || !is_dir($distReal)) {
            return ['OCI dist directory not found: '.$distDir];
        }
        if (!is_file($distReal.'/bin/app')) {
            return ['OCI dist missing bin/app — run phpc deploy first'];
        }

        $nginxDir = $distReal.'/nginx';
        if (!is_dir($nginxDir) && !mkdir($nginxDir, 0777, true) && !is_dir($nginxDir)) {
            return ['cannot create nginx/ under dist'];
        }

        $files = [
            self::OCI_DOCKERFILE => self::ociDockerfileScratch($distReal),
            self::OCI_DOCKERFILE_FCGI => self::ociDockerfileFcgi(),
            self::OCI_COMPOSE => self::ociComposeFcgi(),
            self::OCI_NGINX => self::ociNginxFastcgiConf(),
            self::OCI_README => self::ociReadme(),
        ];
        foreach ($files as $rel => $body) {
            $path = $distReal.'/'.$rel;
            $parent = dirname($path);
            if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
                return ['cannot create directory for '.$rel];
            }
            if (false === file_put_contents($path, $body)) {
                return ['failed to write '.$rel];
            }
        }

        return [];
    }

    /**
     * @param string $distReal Absolute path to an existing deploy dist
     */
    public static function ociDockerfileScratch(string $distReal = ''): string
    {
        $wrapper = CgiAotDriver::WRAPPER_NAME;
        $copies = [
            'COPY bin/app /app/bin/app',
            'COPY '.$wrapper.' /app/'.$wrapper,
            'COPY phpc.json /app/phpc.json',
            'COPY README.deploy /app/README.deploy',
        ];
        if ('' !== $distReal) {
            foreach (['public', 'assets', 'templates'] as $tree) {
                if (is_dir($distReal.'/'.$tree)) {
                    $copies[] = 'COPY '.$tree.'/ /app/'.$tree.'/';
                }
            }
        }
        $copyBlock = implode("\n", $copies);

        return <<<DOCKER
# Generated by phpc deploy --oci (#36392).
# Scratch payload: native AOT binary + public/assets/templates when present.
# Build:  docker build -t myapp:scratch -f Dockerfile .
# Run CGI one-shot (no FastCGI framing):
#   docker run --rm -e REQUEST_METHOD=GET -e QUERY_STRING= -e SCRIPT_NAME=/ \\
#     -e PHPC_DEPLOY_ROOT=/app myapp:scratch
#
# FastCGI on :9000 → use Dockerfile.fcgi or docker-compose.fcgi.yml (needs PHP host).
FROM scratch
WORKDIR /app
{$copyBlock}
ENV PHPC_DEPLOY_ROOT=/app
ENTRYPOINT ["/app/bin/app"]

DOCKER;
    }

    public static function ociDockerfileFcgi(): string
    {
        return <<<'DOCKER'
# Generated by phpc deploy --oci (#36392).
# FastCGI worker on 0.0.0.0:9000 using phpc fcgi + the dist AOT binary.
# Requires the user-facing phpc image (PHP + LLVM runtime for the listener only;
# request bodies execute the AOT binary — see lib/Web/FastCgi/RequestHandler.php).
#
# Build (from the deploy dist directory):
#   docker build -t myapp:fcgi -f Dockerfile.fcgi .
# Run:
#   docker run --rm -p 9000:9000 myapp:fcgi
#
ARG PHPC_IMAGE=ghcr.io/purhur/phpc:dev
FROM ${PHPC_IMAGE}
WORKDIR /app
COPY . /app
ENV PHPC_DEPLOY_ROOT=/app
EXPOSE 9000
# Health: TCP accept on 9000 (no curl in lean images). Operators probe via nginx.
ENTRYPOINT ["phpc", "fcgi", "--binary", "/app/bin/app", "--listen", "0.0.0.0:9000", "/app"]

DOCKER;
    }

    public static function ociComposeFcgi(): string
    {
        return <<<'YAML'
# Generated by phpc deploy --oci (#36392).
# Scratch app volume + FastCGI companion (phpc fcgi) + nginx front.
# From the deploy dist:
#   docker compose -f docker-compose.fcgi.yml up --build
services:
  fcgi:
    build:
      context: .
      dockerfile: Dockerfile.fcgi
    ports:
      - "9000:9000"
    environment:
      PHPC_DEPLOY_ROOT: /app
  nginx:
    image: nginx:1.27-alpine
    depends_on:
      - fcgi
    ports:
      - "8080:80"
    volumes:
      - ./nginx/fastcgi.conf:/etc/nginx/conf.d/default.conf:ro
      - ./assets:/var/www/assets:ro
      - ./public:/var/www/public:ro

YAML;
    }

    public static function ociNginxFastcgiConf(): string
    {
        return <<<'NGINX'
# Generated by phpc deploy --oci (#36392).
# nginx → FastCGI pool (phpc fcgi --listen 0.0.0.0:9000).
# Validate: nginx -t -c <(echo 'events{} http{ include nginx/fastcgi.conf; }')
# CI smoke: script/deploy-oci-smoke.sh (structure + optional nginx -t).
server {
    listen 80;
    server_name _;
    client_max_body_size 8m;

    # Static assets from the deploy dist (not served by bin/app).
    location /assets/ {
        alias /var/www/assets/;
        access_log off;
        expires 7d;
    }

    location / {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /app/bin/app;
        fastcgi_param SCRIPT_NAME /bin/app;
        fastcgi_param DOCUMENT_ROOT /var/www/public;
        fastcgi_param PHPC_DEPLOY_ROOT /app;
        fastcgi_pass fcgi:9000;
    }
}

NGINX;
    }

    public static function ociReadme(): string
    {
        return <<<'TXT'
php-compiler OCI / nginx deploy context (#36392)
================================================

Generated by: phpc deploy <project> -o <dist> --oci

Files
-----
  Dockerfile              FROM scratch — AOT bin/app ENTRYPOINT (CGI one-shot)
  Dockerfile.fcgi         phpc fcgi --binary /app/bin/app --listen 0.0.0.0:9000
  docker-compose.fcgi.yml fcgi + nginx (port 8080 → FastCGI 9000)
  nginx/fastcgi.conf      nginx fastcgi_pass recipe (exercised by deploy-oci-smoke)
  README.oci              this file

Scratch vs FastCGI
------------------
The AOT binary does not speak FastCGI framing. FROM scratch is the payload image
(binary + trees). For `docker run -p 9000:9000`, build Dockerfile.fcgi (or use
compose): the listener is `phpc fcgi`, which dispatches each request to bin/app
via CgiAotDriver (lib/Web/FastCgi/RequestHandler.php; php-src sapi/fpm shape).

Verify
------
  ./script/deploy-oci-smoke.sh
  # optional, when docker is on the host:
  docker build -t myapp:fcgi -f Dockerfile.fcgi .
  docker run --rm -p 9000:9000 myapp:fcgi

See docs/deploy-production.md § OCI.

TXT;
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
