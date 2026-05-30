<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

/**
 * CGI/1.1 driver for native AOT binaries (issue #665).
 *
 * Reads CONTENT_LENGTH + stdin into REQUEST_BODY / REQUEST_BODY_FILE, then execs
 * the compiled binary with the current CGI environment (superglobals_refresh in the binary).
 */
final class CgiAotDriver
{
    public const WRAPPER_NAME = 'cgi-wrapper';

    /**
     * Resolve the AOT binary from an explicit path or PHPC_DEPLOY_ROOT + phpc.json "binary".
     */
    public static function resolveBinary(?string $explicit, ?string $deployRoot = null): string
    {
        if (null !== $explicit && '' !== $explicit) {
            $path = realpath($explicit);
            if (false === $path || !is_file($path)) {
                throw new \InvalidArgumentException('AOT binary not found: '.$explicit);
            }

            return $path;
        }

        $root = $deployRoot ?? getenv(DeployRoot::ENV);
        if (false === $root || '' === $root) {
            throw new \InvalidArgumentException(
                'Usage: cgi-aot <binary> or set '.DeployRoot::ENV.' to a deploy dist directory'
            );
        }

        $resolved = ProjectManifest::resolveBinaryPath($root, null);
        if (null === $resolved) {
            $fallback = realpath($root.'/bin/app');
            if (false !== $fallback && is_file($fallback)) {
                return $fallback;
            }

            throw new \InvalidArgumentException(
                'No AOT binary under '.DeployRoot::ENV.'='.$root.' (phpc.json "binary" or bin/app)'
            );
        }

        return $resolved;
    }

    /**
     * Run an AOT binary under the current CGI environment; stdout is CGI (Status + headers + body).
     */
    public static function run(string $binary, ?string $deployRoot = null): void
    {
        CgiDriver::ingestStdinRequestBody();
        [$status, $contentType, $body, $extraHeaders] = self::runCapture($binary, $deployRoot);
        fwrite(STDOUT, CgiDriver::formatResponse($status, $contentType, $body, $extraHeaders));
        exit(0);
    }

    /**
     * Execute an AOT binary with the current CGI environment and return response parts.
     *
     * @return array{0: int, 1: string, 2: string, 3: list<string>}
     */
    public static function runCapture(string $binary, ?string $deployRoot = null): array
    {
        if (!is_executable($binary)) {
            throw new \InvalidArgumentException('AOT binary is not executable: '.$binary);
        }

        self::ensureDeployRoot($binary, $deployRoot);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = self::cgiEnvironment();
        $proc = proc_open([$binary], $descriptorSpec, $pipes, null, $env);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Failed to start AOT binary: '.$binary);
        }
        if ('' !== ($stdinBody = (string) (getenv('REQUEST_BODY') ?: ''))) {
            fwrite($pipes[0], $stdinBody);
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if (0 !== $code) {
            $detail = trim($stderr !== false ? $stderr : '');
            throw new \RuntimeException(
                'AOT binary exited '.$code.('' !== $detail ? ': '.$detail : '')
            );
        }

        $raw = $stdout !== false ? $stdout : '';
        if (preg_match('/^Status:\s*\d+/im', $raw)) {
            return DevServer::parseCgiOutput($raw);
        }

        return [200, 'text/plain', $raw, []];
    }

    private static function ensureDeployRoot(string $binary, ?string $deployRoot): void
    {
        if (null === $deployRoot || '' === $deployRoot) {
            $deployRoot = getenv(DeployRoot::ENV);
        }
        if (false === $deployRoot || '' === $deployRoot) {
            $parent = dirname($binary);
            if (basename($parent) === 'bin') {
                $guess = realpath(dirname($parent));
                if (false !== $guess) {
                    putenv(DeployRoot::ENV.'='.$guess);
                    $_ENV[DeployRoot::ENV] = $guess;
                    $_SERVER[DeployRoot::ENV] = $guess;
                }
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private static function cgiEnvironment(): array
    {
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $env[$key] = $value;
            }
        }

        return $env;
    }
}
