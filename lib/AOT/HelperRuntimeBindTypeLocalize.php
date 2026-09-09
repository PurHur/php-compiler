<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPCompiler\JIT\Context;

/**
 * Helper-runtime named-struct type remapping for bitcode bind (#15889 / #36387).
 *
 * Extracted from {@see HelperRuntimeBind} so function/type localize stays a
 * separate TU from tryProvide / declareExtern / unit lifecycle / bitcode parse
 * (helper-cache granularity / split-TU / size-budget ratchet).
 * Callers keep using HelperRuntimeBind::tryProvide which delegates here.
 *
 * php-src analogy: Zend opcache persist remaps interned strings / class entries
 * into the consuming executor's arena (Zend/zend_persist.c / zend_file_cache.c) —
 * LLVM re-suffixes named structs on parse (__string__ → __string__.12); call sites
 * must bind declarations rebuilt against the local module's named structs.
 */
final class HelperRuntimeBindTypeLocalize
{
    /**
     * Function type rebuilt from the local context's named structs, or null
     * when any component type is unknown locally (caller falls back to the
     * parsed type verbatim).
     */
    public static function localizedFunctionType(Context $context, object $source, object $fnType): ?object
    {
        $lib = $context->llvm->lib;
        try {
            $params = [];
            for ($i = 0, $n = $source->countParams(); $i < $n; ++$i) {
                $params[] = self::localizedType($context, $lib->LLVMTypeOf($source->getParam($i)->value));
            }
            $ret = self::localizedType($context, $lib->LLVMGetReturnType($fnType));
            if (null === $ret || \in_array(null, $params, true)) {
                return null;
            }

            return $context->context->functionType($ret, false, ...$params);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Localize one raw FFI type: named structs (possibly context-suffixed,
     * __string__.12) map to the local struct of the base name at the same
     * pointer depth; everything else wraps verbatim.
     */
    public static function localizedType(Context $context, object $rawTy): ?object
    {
        $lib = $context->llvm->lib;
        $depth = 0;
        $t = $rawTy;
        while (\llvm\llvm::LLVMPointerTypeKind === $lib->LLVMGetTypeKind($t)) {
            $t = $lib->LLVMGetElementType($t);
            ++$depth;
        }
        if (\llvm\llvm::LLVMStructTypeKind === $lib->LLVMGetTypeKind($t)) {
            $name = $lib->LLVMGetStructName($t);
            $name = \is_object($name) ? $name->toString() : (string) $name;
            if ('' === $name) {
                return null; // anonymous struct — no local identity to map to
            }
            $base = (string) preg_replace('/\\.\\d+$/', '', $name);

            try {
                return $context->getTypeFromString($base.str_repeat('*', $depth));
            } catch (\Throwable) {
                return null;
            }
        }

        return $context->llvm->factory->type($context->context, $rawTy);
    }
}
