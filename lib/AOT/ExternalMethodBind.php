<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;

/**
 * Bound cross-module method calls for spine split-TU / helper-runtime (#24429, #579).
 *
 * Default {@see Call\ExternalMethod} writes null. Emitting an extern for every stub breaks
 * builds that carry unreached stubs (#24227). Bind only when a symbol is known to exist —
 * helper-runtime index or an explicit chunk manifest — and keep the null otherwise.
 */
final class ExternalMethodBind
{
    public const ENV_SPINE_CHUNK = 'PHP_COMPILER_SPINE_CHUNK';
    public const ENV_MANIFEST = 'PHP_COMPILER_EXTERNAL_METHOD_MANIFEST';

    /** @var array<string, array{symbol: string}>|null logical lc → entry */
    private static ?array $manifestIndex = null;

    /** @var array<string, string> logical lc → producer bitcode path */
    private static array $manifestBitcodeByLogical = [];

    private static bool $manifestLoaded = false;

    public static function spineChunkMode(): bool
    {
        $flag = getenv(self::ENV_SPINE_CHUNK);

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    /**
     * When true, unresolved instance methods fall through to ExternalMethod instead of
     * aborting compile — so chunk builds can reach the stub report (#24429).
     */
    public static function allowUnresolvedMethodFallthrough(
        Context $context,
        string $declaringClassLc,
        ?int $classId
    ): bool {
        if (self::spineChunkMode()) {
            return true;
        }
        if (null !== $classId && $context->type->object->isExternalOnlyClass($classId)) {
            return true;
        }

        return false;
    }

    /**
     * Upgrade a missing proxy to a real Native call when the helper-runtime cache (or
     * chunk manifest) knows the symbol. Returns null when unbound — caller keeps the stub.
     */
    public static function tryBind(Context $context, string $proxyName): ?Call
    {
        $lc = strtolower(ltrim($proxyName, '\\'));
        if (isset($context->functionProxies[$lc]) && !($context->functionProxies[$lc] instanceof Call\ExternalMethod)) {
            return $context->functionProxies[$lc];
        }

        if (HelperRuntimeCache::enabled()) {
            HelperRuntimeCache::tryProvide($context, [$proxyName]);
        }

        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            $symbol = self::manifestSymbol($lc);
            if (null !== $symbol) {
                $fn = self::declareExternFromManifest($context, $lc, $symbol);
            }
        }
        if (null === $fn) {
            return null;
        }

        // Native::$argTypes must be PHPLLVM\Type objects — callers use getStringFromType() (#24636).
        $argTypes = [];
        for ($i = 0, $n = $fn->countParams(); $i < $n; ++$i) {
            $argTypes[] = $fn->getParam($i)->typeOf();
        }
        $native = new Call\Native($fn, $proxyName, $argTypes);
        $context->functionProxies[$lc] = $native;

        return $native;
    }

    public static function resetManifestForTests(): void
    {
        self::$manifestIndex = null;
        self::$manifestBitcodeByLogical = [];
        self::$manifestLoaded = false;
    }

    private static function manifestSymbol(string $logicalLc): ?string
    {
        $index = self::manifestIndex();
        if (!isset($index[$logicalLc])) {
            return null;
        }

        return $index[$logicalLc]['symbol'];
    }

    /**
     * @return array<string, array{symbol: string}>
     */
  private static function manifestIndex(): array
    {
        if (self::$manifestLoaded) {
            return self::$manifestIndex ?? [];
        }
        self::$manifestLoaded = true;
        self::$manifestIndex = [];
        self::$manifestBitcodeByLogical = [];
        $pathEnv = getenv(self::ENV_MANIFEST);
        if (!is_string($pathEnv) || '' === $pathEnv) {
            return self::$manifestIndex;
        }
        foreach (self::manifestPaths($pathEnv) as $path) {
            self::mergeManifestFile($path);
        }

        return self::$manifestIndex;
    }

    /**
     * @return list<string>
     */
    private static function manifestPaths(string $pathEnv): array
    {
        $paths = [];
        foreach (preg_split('/[:;]/', $pathEnv) ?: [] as $candidate) {
            if (!is_string($candidate) || '' === $candidate) {
                continue;
            }
            if (!is_file($candidate)) {
                continue;
            }
            $paths[] = $candidate;
        }

        return $paths;
    }

    private static function mergeManifestFile(string $path): void
    {
        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) {
            return;
        }
        $bitcodePath = null;
        if (isset($raw['bitcode']) && is_string($raw['bitcode']) && '' !== $raw['bitcode']) {
            $bc = $raw['bitcode'];
            if (!str_starts_with($bc, '/')) {
                $bc = dirname($path).'/'.$bc;
            }
            if (is_file($bc)) {
                $bitcodePath = $bc;
            }
        }
        $methods = $raw['methods'] ?? $raw;
        if (!is_array($methods)) {
            return;
        }
        foreach ($methods as $logical => $entry) {
            if (!is_string($logical) || '' === $logical) {
                continue;
            }
            $symbol = null;
            if (is_string($entry)) {
                $symbol = $entry;
            } elseif (is_array($entry) && isset($entry['symbol']) && is_string($entry['symbol'])) {
                $symbol = $entry['symbol'];
            }
            if (null === $symbol || '' === $symbol) {
                continue;
            }
            $logicalLc = strtolower($logical);
            self::$manifestIndex[$logicalLc] = ['symbol' => $symbol];
            if (null !== $bitcodePath) {
                self::$manifestBitcodeByLogical[$logicalLc] = $bitcodePath;
            }
        }
    }

    private static function declareExternFromManifest(Context $context, string $logicalLc, string $symbol): ?object
    {
        $existing = $context->module->getNamedFunction($symbol);
        if (null !== $existing) {
            $context->functions[$logicalLc] = $existing;

            return $existing;
        }
        $bitcode = self::$manifestBitcodeByLogical[$logicalLc] ?? null;
        if (null === $bitcode || '' === $bitcode) {
            return null;
        }
        $fn = HelperRuntimeCache::declareExternFromBitcode($context, $symbol, $bitcode);
        if (null !== $fn) {
            $context->functions[$logicalLc] = $fn;
        }

        return $fn;
    }
}
