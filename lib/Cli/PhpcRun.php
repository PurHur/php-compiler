<?php

declare(strict_types=1);

namespace PHPCompiler\Cli;

use PHPCompiler\Web\CgiAotDriver;
use PHPCompiler\Web\DeployRoot;
use PHPCompiler\Web\ProjectManifest;

/**
 * phpc run --project: exec AOT binary with CGI env (issue #774).
 */
final class PhpcRun
{
    /** Exit when --require-nonempty-stdout and stdout is empty (binary may have exited 0). */
    public const EXIT_EMPTY_STDOUT = 2;

    /**
     * @param list<string> $args arguments after "phpc run"
     * @param list<string> $php    host PHP argv prefix
     */
    public static function main(array $args, string $repoRoot, array $php): int
    {
        $options = self::parseOptions($args);

        if (null !== $options['project']) {
            return self::runProject($options);
        }

        if ([] !== $options['cgi_env'] || null !== $options['cgi_env_file'] || null !== $options['deploy_root']) {
            fwrite(STDERR, "phpc run: --cgi-env, --cgi-env-file, and --deploy-root require --project\n");

            return 1;
        }

        if ([] === $options['vm_args']) {
            fwrite(STDERR, "phpc run: missing script.php\n");

            return 1;
        }

        return self::runVm($repoRoot, $php, $options['vm_args']);
    }

    /**
     * @return list<string> KEY=VAL lines from an env file
     */
    public static function loadCgiEnvFile(string $path): array
    {
        $resolved = InvokeCwd::resolve($path);
        if (!is_readable($resolved)) {
            throw new \InvalidArgumentException('phpc run: cannot read --cgi-env-file: '.$path);
        }

        $pairs = [];
        $lines = file($resolved, FILE_IGNORE_NEW_LINES);
        if (false === $lines) {
            throw new \InvalidArgumentException('phpc run: cannot read --cgi-env-file: '.$path);
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }
            $line = trim($line);
            if ('' === $line || !str_contains($line, '=')) {
                fwrite(STDERR, "phpc run: skipping invalid env line: {$line}\n");
                continue;
            }
            $pairs[] = $line;
        }

        return $pairs;
    }

    /**
     * @param list<string> $pairs KEY=VAL
     */
    public static function applyCgiEnvPairs(array $pairs): void
    {
        foreach ($pairs as $pair) {
            self::applyCgiEnvPair($pair);
        }
    }

    public static function applyCgiEnvPair(string $pair): void
    {
        if (!str_contains($pair, '=')) {
            throw new \InvalidArgumentException('phpc run: --cgi-env requires KEY=VAL, got: '.$pair);
        }
        [$key, $value] = explode('=', $pair, 2);
        if ('' === $key) {
            throw new \InvalidArgumentException('phpc run: --cgi-env requires non-empty KEY=VAL');
        }
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    /**
     * @return int process exit code after optional empty-stdout guard
     */
    public static function finalizeExit(int $binaryExit, string $stdout, bool $requireNonemptyStdout): int
    {
        if ($requireNonemptyStdout && '' === $stdout) {
            fwrite(STDERR, "phpc run: empty stdout (--require-nonempty-stdout)\n");

            return self::EXIT_EMPTY_STDOUT;
        }

        return $binaryExit;
    }

    /**
     * @param list<string> $args
     *
     * @return array{
     *     project: ?string,
     *     cgi_env: list<string>,
     *     cgi_env_file: ?string,
     *     deploy_root: ?string,
     *     require_nonempty_stdout: bool,
     *     vm_args: list<string>
     * }
     */
    private static function parseOptions(array $args): array
    {
        $project = null;
        $cgiEnv = [];
        $cgiEnvFile = null;
        $deployRoot = null;
        $requireNonemptyStdout = false;
        $vmArgs = [];

        while ([] !== $args) {
            $arg = array_shift($args);
            if ('--project' === $arg) {
                $project = [] !== $args ? array_shift($args) : '.';
                continue;
            }
            if ('--cgi-env' === $arg) {
                if ([] === $args) {
                    throw new \InvalidArgumentException('phpc run: --cgi-env requires KEY=VAL');
                }
                $cgiEnv[] = array_shift($args);
                continue;
            }
            if ('--cgi-env-file' === $arg) {
                if ([] === $args) {
                    throw new \InvalidArgumentException('phpc run: --cgi-env-file requires a path');
                }
                $cgiEnvFile = array_shift($args);
                continue;
            }
            if ('--deploy-root' === $arg) {
                if ([] === $args) {
                    throw new \InvalidArgumentException('phpc run: --deploy-root requires a path');
                }
                $deployRoot = array_shift($args);
                continue;
            }
            if ('--require-nonempty-stdout' === $arg) {
                $requireNonemptyStdout = true;
                continue;
            }
            $vmArgs[] = $arg;
        }

        return [
            'project' => $project,
            'cgi_env' => $cgiEnv,
            'cgi_env_file' => $cgiEnvFile,
            'deploy_root' => $deployRoot,
            'require_nonempty_stdout' => $requireNonemptyStdout,
            'vm_args' => $vmArgs,
        ];
    }

    /**
     * @param array{
     *     project: ?string,
     *     cgi_env: list<string>,
     *     cgi_env_file: ?string,
     *     deploy_root: ?string,
     *     require_nonempty_stdout: bool,
     *     vm_args: list<string>
     * } $options
     */
    private static function runProject(array $options): int
    {
        if ([] !== $options['vm_args']) {
            fwrite(STDERR, "phpc run --project: unexpected positional arguments: ".implode(' ', $options['vm_args'])."\n");

            return 1;
        }

        try {
            $pairs = $options['cgi_env'];
            if (null !== $options['cgi_env_file']) {
                $pairs = array_merge($pairs, self::loadCgiEnvFile($options['cgi_env_file']));
            }
            self::applyCgiEnvPairs($pairs);

            $projectDir = InvokeCwd::resolve($options['project'] ?? '.');
            $projectReal = realpath($projectDir);
            if (false === $projectReal) {
                fwrite(STDERR, "phpc run --project: directory not found: {$projectDir}\n");

                return 1;
            }

            $deployRoot = null;
            if (null !== $options['deploy_root']) {
                $deployRoot = InvokeCwd::resolve($options['deploy_root']);
                $deployReal = realpath($deployRoot);
                if (false === $deployReal || !is_dir($deployReal)) {
                    fwrite(STDERR, "phpc run --project: deploy root not found: {$deployRoot}\n");

                    return 1;
                }
                putenv(DeployRoot::ENV.'='.$deployReal);
                $_ENV[DeployRoot::ENV] = $deployReal;
                $_SERVER[DeployRoot::ENV] = $deployReal;
            }

            if (null !== $deployRoot) {
                $binary = CgiAotDriver::resolveBinary(null, $deployRoot);
            } else {
                $binary = ProjectManifest::resolveBinaryPath($projectReal);
                if (null === $binary) {
                    $candidate = ProjectManifest::resolveBinaryOutputPath($projectReal);
                    if (null !== $candidate && is_file($candidate)) {
                        $binary = $candidate;
                    }
                }
            }

            if (null === $binary || !is_file($binary)) {
                $expected = ProjectManifest::resolveBinaryOutputPath($projectReal) ?? '(unknown)';
                fwrite(STDERR, "phpc run --project: binary not found: {$expected}\n");
                fwrite(STDERR, "  run: phpc build --project {$projectReal}\n");

                return 1;
            }

            if (!is_executable($binary)) {
                fwrite(STDERR, "phpc run --project: binary is not executable: {$binary}\n");

                return 1;
            }

            return self::execBinary($binary, $options['require_nonempty_stdout']);
        } catch (\InvalidArgumentException $e) {
            fwrite(STDERR, $e->getMessage()."\n");

            return 1;
        }
    }

    private static function execBinary(string $binary, bool $requireNonemptyStdout): int
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = self::processEnvironment();
        $proc = proc_open([$binary], $descriptorSpec, $pipes, null, $env);
        if (!is_resource($proc)) {
            fwrite(STDERR, "phpc run --project: failed to start: {$binary}\n");

            return 1;
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        $stdoutStr = false !== $stdout ? $stdout : '';
        $stderrStr = false !== $stderr ? $stderr : '';
        if ('' !== $stderrStr) {
            fwrite(STDERR, $stderrStr);
        }
        fwrite(STDOUT, $stdoutStr);

        $exit = is_int($code) ? $code : 1;

        return self::finalizeExit($exit, $stdoutStr, $requireNonemptyStdout);
    }

    /**
     * @return array<string, string>
     */
    private static function processEnvironment(): array
    {
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $env[$key] = $value;
            }
        }

        return $env;
    }

    /**
     * @param list<string> $php
     * @param list<string> $vmArgs
     */
    private static function runVm(string $repoRoot, array $php, array $vmArgs): int
    {
        $cmd = array_merge($php, [$repoRoot.'/bin/vm.php'], $vmArgs);
        $descriptorSpec = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $repoRoot);
        if (!is_resource($proc)) {
            fwrite(STDERR, 'phpc run: failed to start VM: '.implode(' ', $cmd)."\n");

            return 1;
        }
        $code = proc_close($proc);

        return is_int($code) ? $code : 1;
    }
}
