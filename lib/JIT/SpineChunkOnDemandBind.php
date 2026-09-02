<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\AOT\ExternalMethodBind;
use PHPCompiler\JIT\Call;

/**
 * Shared on-demand NestedJIT bind for spine split-TU consumer chunks (#36155 Phase C).
 *
 * Compiles an isolated producer file when a cross-chunk call would otherwise become
 * {@see Call\ExternalMethod} null (#579).
 */
final class SpineChunkOnDemandBind
{
    /**
     * @param array<string, string> $classFiles      lowercase short class name → repo-root path
     * @param list<string>          $allowedPrefixes proxy must start with one of these (lowercase)
     */
    public static function tryBind(
        Context $context,
        string $proxyName,
        array $classFiles,
        array $allowedPrefixes,
        string $compileLabel,
        bool $requireMethodSeparator = true,
    ): ?Call {
        if (!ExternalMethodBind::spineChunkMode()) {
            return null;
        }
        $lc = strtolower(ltrim($proxyName, '\\'));
        if (!self::matchesPrefix($lc, $allowedPrefixes)) {
            return null;
        }
        if ($requireMethodSeparator && !str_contains($lc, '::')) {
            return null;
        }
        if (str_contains($lc, '::')) {
            [$classLc, $_methodLc] = explode('::', $lc, 2);
            $short = substr($classLc, strrpos($classLc, '\\') + 1);
        } else {
            $classLc = $lc;
            $short = substr($lc, strrpos($lc, '\\') + 1);
        }
        $path = $classFiles[$short] ?? null;
        if (null === $path) {
            return null;
        }
        $bound = self::lookupBound($context, $lc, $short);
        if (null !== $bound) {
            return $bound;
        }
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        try {
            JitVmHelperLink::ensureCompiled(
                $context,
                $path,
                self::expectedHelperNames($lc, $short),
                $compileLabel,
                true
            );
        } catch (\Throwable) {
            return null;
        } finally {
            if (null !== $savedInsert) {
                $context->builder->positionAtEnd($savedInsert);
            } else {
                $context->builder->clearInsertionPosition();
            }
        }

        return self::lookupBound($context, $lc, $short);
    }

    /**
     * @param list<string> $allowedPrefixes
     */
    private static function matchesPrefix(string $lc, array $allowedPrefixes): bool
    {
        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($lc, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function expectedHelperNames(string $lc, string $short): array
    {
        if ($lc === $short) {
            return [$lc];
        }
        $names = [$lc];
        if (!str_contains($lc, '::')) {
            $names[] = $short;
        }

        return $names;
    }

    private static function lookupBound(Context $context, string $lc, string $short): ?Call
    {
        foreach ([$lc, $short] as $name) {
            if (isset($context->functionProxies[$name])) {
                $existing = $context->functionProxies[$name];
                if (!$existing instanceof Call\ExternalMethod) {
                    return $existing;
                }
            }
            if (isset($context->functions[$name])) {
                return self::nativeFromLlvm($context, $name, $lc);
            }
        }

        return null;
    }

    private static function nativeFromLlvm(Context $context, string $registeredLc, string $proxyLc): Call
    {
        $fn = $context->functions[$registeredLc];
        $argTypes = [];
        for ($i = 0, $n = $fn->countParams(); $i < $n; ++$i) {
            $argTypes[] = $fn->getParam($i)->typeOf();
        }
        $native = new Call\Native($fn, $proxyLc, $argTypes);
        $context->functionProxies[$proxyLc] = $native;
        if ($registeredLc !== $proxyLc) {
            $context->functionProxies[$registeredLc] = $native;
        }

        return $native;
    }
}
