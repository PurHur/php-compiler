<?php

declare(strict_types=1);

namespace PHPCompiler\Web;

use PHPCompiler\Runtime;

/**
 * VM PSR-4 autoload for compiler vendor spine (PHPCfg, PhpParser, PHPTypes).
 *
 * Self-host bundles require lib/ units that extend vendor classes; without this,
 * TYPE_DECLARE_CLASS fails with "extends unknown class" (#1492).
 */
final class VendorSpineAutoload
{
    /** @var array<string, string> namespace prefix => repo-relative base directory */
    private const PREFIX_MAP = [
        'PHPCfg\\' => 'vendor/ircmaxell/php-cfg/lib/PHPCfg/',
        'PhpParser\\' => 'vendor/nikic/php-parser/lib/PhpParser/',
        'PHPTypes\\' => 'vendor/ircmaxell/php-types/lib/PHPTypes/',
    ];

    public static function register(Runtime $runtime): void
    {
        $root = dirname(__DIR__, 2);
        $runtime->vmContext->classAutoloaders[] = new VendorVmAutoloadHandler($runtime, $root, self::PREFIX_MAP);
    }

    public static function resolveClassPath(string $className, string $repoRoot, array $prefixMap): ?string
    {
        foreach ($prefixMap as $prefix => $relativeBase) {
            if (!str_starts_with($className, $prefix)) {
                continue;
            }
            $rel = substr($className, strlen($prefix));
            if ('' === $rel) {
                continue;
            }
            $path = $repoRoot.'/'.$relativeBase.str_replace('\\', '/', $rel).'.php';
            $resolved = realpath($path);

            return false !== $resolved && is_file($resolved) ? $resolved : null;
        }

        return null;
    }
}

/** VM class autoload callback without Expr_Closure (self-host AOT spine #1056). */
final class VendorVmAutoloadHandler
{
    /**
     * @param array<string, string> $prefixMap
     */
    public function __construct(
        private Runtime $runtime,
        private string $repoRoot,
        private array $prefixMap
    ) {
    }

    public function __invoke(string $className): bool
    {
        $path = VendorSpineAutoload::resolveClassPath($className, $this->repoRoot, $this->prefixMap);
        if (null === $path) {
            return false;
        }
        $this->runtime->vm()->executeCompileUnit($path);

        return isset($this->runtime->vmContext->classes[strtolower($className)]);
    }
}
