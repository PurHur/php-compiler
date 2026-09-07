<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Func;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Variable;

/**
 * Property-hook frame identity + static property hook link for the VM (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code frameIsPropertySetHook} through
 * {@code methodIsStatic} (php-src Zend/zend_property_hooks.c hook frame naming /
 * external catch abort; static property hook registry — #9670, #10005, #23532;
 * Zend/zend_compile.c FLAG_STATIC on method decls). Companion to
 * {@see ObjectPropertyHooks} / {@see VirtualPropertyHookEnforce}. Concern trait —
 * same namespace as parent so relative Frame helpers resolve. Move-only; no new C ABI.
 */
trait PropertyHookFrameAndStaticLink
{
    private function frameIsPropertySetHook(Frame $frame): bool
    {
        $func = $frame->block->func ?? null;
        if (null === $func) {
            return false;
        }
        $name = strtolower($func->name);

        return str_contains($name, '__phpc_property_set_');
    }

    /**
     * Route catchable hook failures to the caller stack (#9670, #10005, zend_property_hooks.c).
     */
    private function stashPropertyHookSetExternalCatch(Frame $frame, Frame $catchFrame): bool
    {
        if (
            null === $frame->propertyHookRawProperty
            && !$this->frameIsPropertySetHook($frame)
            && !$this->frameIsPropertyGetHook($frame)
        ) {
            return false;
        }
        $this->context->propertyHookExternalCatchFrame = $catchFrame;

        return true;
    }

    private function shouldAbortPropertyHookInvocation(Frame $frame): bool
    {
        if (null === $this->context->propertyHookExternalCatchFrame) {
            return false;
        }
        if (null === $frame->propertyHookRawProperty && !$this->frameIsPropertySetHook($frame)) {
            return false;
        }
        $this->context->propertyHookSetAborted = true;

        return true;
    }

    private function frameIsPropertyGetHook(Frame $frame): bool
    {
        $func = $frame->block->func ?? null;
        if (null === $func) {
            return false;
        }
        $name = strtolower($func->name);

        return str_contains($name, '__phpc_property_get_');
    }

    private function frameIsPropertyUnsetHook(Frame $frame): bool
    {
        $func = $frame->block->func ?? null;
        if (null === $func) {
            return false;
        }
        $name = strtolower($func->name);

        return str_contains($name, '__phpc_property_unset_');
    }

    private function isPropertyHookRawWrite(Frame $frame, string $propName): bool
    {
        if ($propName === $frame->propertyHookRawProperty) {
            return true;
        }
        $func = $frame->block->func ?? null;
        if (null === $func || null === $func->class) {
            return false;
        }
        $className = $func->class->value ?? null;
        if (!is_string($className) || '' === $className) {
            return false;
        }
        $methodLc = strtolower((string) $func->name);
        if (str_contains($methodLc, '::')) {
            $methodLc = substr($methodLc, strrpos($methodLc, '::') + 2);
        }
        $wantSet = strtolower(SourcePreprocessor\PropertyHooks::setHookMethodName($propName));
        $wantGet = strtolower(SourcePreprocessor\PropertyHooks::getHookMethodName($propName));
        $wantUnset = strtolower(SourcePreprocessor\PropertyHooks::unsetHookMethodName($propName));

        return $methodLc === $wantSet
            || $methodLc === $wantGet
            || $methodLc === $wantUnset
            || $methodLc === strtolower($className.'::'.$wantSet)
            || $methodLc === strtolower($className.'::'.$wantGet)
            || $methodLc === strtolower($className.'::'.$wantUnset);
    }

    private function linkStaticTypedPropertySlot(Variable $storage, ClassEntry $entry, string $propDisplayName): void
    {
        // Always keep declared casing for property_exists() exact match (#23532).
        $storage->objectPropertyName = $propDisplayName;
        if (!$storage->hasDeclaredTypeConstraint()) {
            return;
        }
        $storage->staticPropertyClassLc = strtolower($entry->name);
    }

    private function linkStaticPropertyHooks(ClassEntry $entry): void
    {
        foreach (array_keys($entry->staticProperties) as $propLc) {
            $hooks = [];
            $setLc = strtolower(SourcePreprocessor\PropertyHooks::setHookMethodName($propLc));
            if (isset($entry->methods[$setLc]) && $this->methodIsStatic($entry->methods[$setLc])) {
                $hooks['set'] = $setLc;
            }
            $getLc = strtolower(SourcePreprocessor\PropertyHooks::getHookMethodName($propLc));
            if (isset($entry->methods[$getLc]) && $this->methodIsStatic($entry->methods[$getLc])) {
                $hooks['get'] = $getLc;
            }
            $unsetLc = strtolower(SourcePreprocessor\PropertyHooks::unsetHookMethodName($propLc));
            if (isset($entry->methods[$unsetLc]) && $this->methodIsStatic($entry->methods[$unsetLc])) {
                $hooks['unset'] = $unsetLc;
            }
            if ([] !== $hooks) {
                $lcClass = strtolower($entry->name);
                $propMeta = $this->context->propertyHookRegistry[$lcClass][$propLc] ?? null;
                if (is_array($propMeta) && !empty($propMeta['virtual'])) {
                    $hooks['virtual'] = true;
                }
                $entry->staticPropertyHooks[$propLc] = $hooks;
            }
        }
    }

    private function methodIsStatic(Func $func): bool
    {
        if (!$func instanceof Func\PHP) {
            return false;
        }
        $decl = $func->block->func;

        return null !== $decl && (($decl->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) !== 0;
    }

    /**
     * php-cfg MagicStringResolver lowers parent:: to the direct parent class name; treat
     * static-looking calls to that class from an instance method as parent-scope (#1858, #6735).
     */
}
