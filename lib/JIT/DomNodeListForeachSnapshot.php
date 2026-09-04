<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Thin-AOT DOMNodeList / DOMNamedNodeMap foreach via DomExtensionHooks (#36204).
 *
 * Kernel lives in {@code ext/dom/JitDomNodeListForeachSnapshot.php}; lib must
 * not import {@code ext\dom}. Null-safe when Dom Module::jitInit has not run
 * (nested helper compiles).
 */
final class DomNodeListForeachSnapshot
{
    public static function isDomNodeListForeach(Context $context, ?string $containerUserType): bool
    {
        return $context->extensionLowering->dom?->isDomNodeListForeach($containerUserType) ?? false;
    }

    public static function canLower(Context $context, Variable $array, ?string $containerUserType): bool
    {
        return $context->extensionLowering->dom?->canLowerNodeListForeach(
            $context,
            $array,
            $containerUserType
        ) ?? false;
    }

    public static function compileReset(
        Context $context,
        Variable $array,
        Variable $slotKey,
        ?string $containerUserType
    ): void {
        $dom = $context->extensionLowering->dom;
        if (null === $dom) {
            throw new \LogicException(
                'DOMNodeList foreach snapshot requires DomExtensionHooks (#36204)'
            );
        }
        $dom->compileNodeListForeachReset(
            $context,
            $array,
            $slotKey,
            $containerUserType
        );
    }
}
