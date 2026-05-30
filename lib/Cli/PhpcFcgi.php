<?php

declare(strict_types=1);

namespace PHPCompiler\Cli;

use PHPCompiler\Web\CgiAotDriver;
use PHPCompiler\Web\FastCgi\Listener;
use PHPCompiler\Web\ProjectManifest;

/**
 * phpc fcgi — long-lived FastCGI worker (issue #2427, adapter #173).
 */
final class PhpcFcgi
{
    private const DEFAULT_LISTEN = '127.0.0.1:9000';

    /**
     * @param list<string> $args Arguments after "phpc fcgi" or from bin/fcgi.php ($argv sans script)
     */
    public static function main(array $args): int
    {
        if (in_array($args[0] ?? '', ['-h', '--help', 'help'], true)) {
            self::printHelp();

            return 0;
        }

        try {
            $options = self::parseOptions($args);
        } catch (\InvalidArgumentException $e) {
            fwrite(STDERR, $e->getMessage()."\n");

            return 1;
        }

        $projectDir = $options['project'];
        $docrootArg = $options['docroot'] ?? ($projectDir ?? getcwd());
        if (null === $docrootArg || '' === $docrootArg) {
            fwrite(STDERR, "phpc fcgi: missing project directory or docroot\n");

            return 1;
        }

        $projectDirResolved = null;
        if (null !== $projectDir) {
            $projectReal = realpath(InvokeCwd::resolve($projectDir));
            if (false === $projectReal || !is_dir($projectReal)) {
                fwrite(STDERR, "phpc fcgi --project: directory not found: {$projectDir}\n");

                return 1;
            }
            $projectDirResolved = $projectReal;
            if (null === ProjectManifest::resolveProjectDir($projectReal)) {
                fwrite(STDERR, "phpc fcgi --project: phpc.json not found in {$projectDir}\n");

                return 1;
            }
        }

        $docroot = ProjectManifest::resolvePublicDir($docrootArg);
        if (null !== $projectDirResolved) {
            $docroot = ProjectManifest::resolvePublicDir($projectDirResolved);
        }

        $aotBinary = $options['binary'];
        if (null === $aotBinary && null !== $projectDirResolved) {
            $aotBinary = ProjectManifest::resolveBinaryPath($projectDirResolved);
        }

        if (null !== $aotBinary) {
            try {
                $aotBinary = CgiAotDriver::resolveBinary($aotBinary, $projectDirResolved);
            } catch (\Throwable $e) {
                fwrite(STDERR, 'phpc fcgi: '.$e->getMessage()."\n");

                return 1;
            }
        }

        try {
            Listener::serve($options['listen'], $docroot, $aotBinary);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'phpc fcgi: '.$e->getMessage()."\n");

            return 1;
        }

        return 0;
    }

    public static function printHelp(): void
    {
        fwrite(STDOUT, <<<'HELP'
phpc fcgi — FastCGI worker (issue #2427, protocol adapter #173)

  phpc fcgi [--listen host:port] [--project dir] [docroot]
  phpc fcgi --binary <path> [--listen host:port] [docroot]

Options:
  --listen host:port     TCP bind address (default: 127.0.0.1:9000)
  --listen=host:port     Same as --listen
  --project [dir]        Resolve docroot from phpc.json "public"; optional AOT
                         binary from manifest "binary" when built (#106)
  --binary <path>        Serve requests via AOT binary (overrides manifest default)
  --binary=<path>        Same as --binary
  -h, --help             Show this help

Examples:
  phpc fcgi --project examples/009-FastCGIWeb
  phpc fcgi --listen 127.0.0.1:9000 examples/009-FastCGIWeb/public
  phpc fcgi --binary examples/009-FastCGIWeb/.phpc/bin/app examples/009-FastCGIWeb

Production nginx + deploy bundle: docs/deploy-web-aot.md (#445)
CI smoke (opt-in): FASTCGI_SMOKE_GATE=1 ./script/ci-local.sh --filter FastCgi

HELP);
    }

    /**
     * @param list<string> $args
     *
     * @return array{listen: string, project: ?string, binary: ?string, docroot: ?string}
     */
    private static function parseOptions(array $args): array
    {
        $listen = self::DEFAULT_LISTEN;
        $project = null;
        $binary = null;
        $docroot = null;

        while ([] !== $args) {
            $arg = array_shift($args);
            if ('--listen' === $arg) {
                $listen = array_shift($args) ?? '';
                if ('' === $listen) {
                    throw new \InvalidArgumentException('phpc fcgi: --listen requires host:port');
                }
                continue;
            }
            if (str_starts_with($arg, '--listen=')) {
                $listen = substr($arg, strlen('--listen='));
                if ('' === $listen) {
                    throw new \InvalidArgumentException('phpc fcgi: --listen requires host:port');
                }
                continue;
            }
            if ('--project' === $arg) {
                $project = array_shift($args);
                if (null === $project) {
                    $project = '.';
                }
                continue;
            }
            if (str_starts_with($arg, '--project=')) {
                $project = substr($arg, strlen('--project='));
                if ('' === $project) {
                    $project = '.';
                }
                continue;
            }
            if ('--binary' === $arg) {
                $binary = array_shift($args) ?? '';
                if ('' === $binary) {
                    throw new \InvalidArgumentException('phpc fcgi: --binary requires path');
                }
                continue;
            }
            if (str_starts_with($arg, '--binary=')) {
                $binary = substr($arg, strlen('--binary='));
                if ('' === $binary) {
                    throw new \InvalidArgumentException('phpc fcgi: --binary requires path');
                }
                continue;
            }
            if (str_starts_with($arg, '-')) {
                throw new \InvalidArgumentException('phpc fcgi: unknown option: '.$arg);
            }
            $docroot = $arg;
        }

        return [
            'listen' => $listen,
            'project' => $project,
            'binary' => $binary,
            'docroot' => $docroot,
        ];
    }
}
