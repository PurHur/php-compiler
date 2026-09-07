<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Compiler\NoDiscardMetadata;
use PHPCompiler\Func;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * #[\Deprecated] / #[\NoDiscard] runtime notice emission for the VM (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code emitCallDeprecationNotice} through
 * {@code emitPropertyWriteDeprecation} (php-src Zend/zend_execute_API.c /
 * zend_error(E_DEPRECATED); Zend/zend_object_handlers.c property access;
 * rfc:deprecated_attribute / rfc:deprecated_traits; #26370 property hooks;
 * #22989 trait use; #29380 class-const declaring owner).
 * Concern trait — same namespace as parent so relative Frame / OpCode helpers resolve.
 * Move-only; no new C ABI.
 */
trait DeprecationNoticeEmit
{
    private function emitCallDeprecationNotice(Frame $frame): void
    {
        if (null === $frame->call || !($frame->call instanceof Func\PHP)) {
            return;
        }
        $meta = $frame->call->deprecated;
        if (null === $meta) {
            return;
        }
        $name = $frame->call->getName();
        // Property-hook methods: Zend message is Class::$prop::get/set (#26370).
        $hookMessage = $this->formatPropertyHookDeprecationMessage($meta, $name, null);
        if (null !== $hookMessage) {
            $this->emitDeprecatedNotice($hookMessage, $frame);

            return;
        }
        // Bare #[\Deprecated] emits too (rfc:deprecated_attribute / #27825).
        if (!$meta->emitsRuntimeNotice()) {
            return;
        }
        if (str_contains($name, '::')) {
            [$class, $method] = explode('::', $name, 2);
            $message = $meta->formatMethod($class, $method);
        } else {
            $message = $meta->formatFunction($name);
        }
        $this->emitDeprecatedNotice($message, $frame);
    }

    /**
     * #[\Deprecated] on property get/set hooks — Zend Method Class::$prop::get/set() (#26370).
     *
     * Hook dispatch bypasses FUNCCALL_EXEC ({@see invokePhpFunctionWithPropertyHookRaw}).
     */
    private function emitPropertyHookDeprecationNotice(
        Func\PHP $func,
        string $rawProperty,
        Frame $frame
    ): void {
        $meta = $func->deprecated;
        if (null === $meta) {
            return;
        }
        $message = $this->formatPropertyHookDeprecationMessage($meta, $func->getName(), $rawProperty);
        if (null === $message) {
            return;
        }
        $this->emitDeprecatedNotice($message, $frame);
    }

    /**
     * @return ?string Zend-shaped deprecation, or null when $name is not a property-hook method
     */
    private function formatPropertyHookDeprecationMessage(
        \PHPCompiler\Compiler\DeprecatedMetadata $meta,
        string $qualifiedName,
        ?string $rawProperty
    ): ?string {
        $methodPart = $qualifiedName;
        $class = '';
        if (str_contains($qualifiedName, '::')) {
            [$class, $methodPart] = explode('::', $qualifiedName, 2);
        }
        $methodLc = strtolower($methodPart);
        $prop = SourcePreprocessor\PropertyHooks::propertyNameFromGetHookMethod($methodLc);
        $hook = 'get';
        if (null === $prop) {
            $prop = SourcePreprocessor\PropertyHooks::propertyNameFromSetHookMethod($methodLc);
            $hook = 'set';
        }
        if (null === $prop) {
            return null;
        }
        if (is_string($rawProperty) && '' !== $rawProperty) {
            $prop = $rawProperty;
        }
        if ('' === $class) {
            $class = 'unknown';
        }

        return $meta->formatPropertyHook($class, $prop, $hook);
    }

    private function emitCallNoDiscardNotice(Frame $frame, OpCode $op): void
    {
        if (!CompilerVersion::supportsNoDiscardAttribute()) {
            return;
        }
        if (OpCode::TYPE_FUNCCALL_EXEC_NORETURN !== $op->type) {
            return;
        }
        if (null === $frame->call || !($frame->call instanceof Func\PHP)) {
            return;
        }
        if (!$frame->call->block->noDiscard) {
            return;
        }
        $meta = new NoDiscardMetadata($frame->call->block->noDiscardMessage);
        $name = $frame->call->getName();
        if (str_contains($name, '::')) {
            [$class, $method] = explode('::', $name, 2);
            $message = $meta->formatMethod($class, $method);
        } else {
            $message = $meta->formatFunction($name);
        }
        $line = (int) ($op->arg1 ?? 0);
        $this->context->errors->triggerError(
            $message,
            VM\ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $this->context,
            $frame,
            $line > 0 ? $line : 0
        );
    }

    private function emitDeprecatedNotice(string $message, Frame $frame): void
    {
        if (!CompilerVersion::supportsDeprecatedAttributeRuntimeNotices()) {
            return;
        }
        $this->context->errors->triggerError(
            $message,
            ErrorReporter::E_USER_DEPRECATED,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $this->context,
            $frame
        );
    }

    private function emitClassInstantiationDeprecation(ClassEntry $class, Frame $frame): void
    {
        if (null === $class->classDeprecated || !$class->classDeprecated->emitsRuntimeNotice()) {
            return;
        }
        $this->emitDeprecatedNotice($class->classDeprecated->formatClass($class->name), $frame);
    }

    /**
     * PHP 8.5+ #[\Deprecated] on traits — notice when the trait is directly `use`d (#22989).
     *
     * Bare `#[\Deprecated]` (no message/since) still emits (rfc:deprecated_traits); children that
     * inherit a class using the trait do not re-emit unless they `use` it again.
     */
    private function emitTraitUseDeprecation(ClassEntry $trait, ClassEntry $user, ?Frame $frame = null): void
    {
        if (!CompilerVersion::supportsDeprecatedTraitAttribute()) {
            return;
        }
        $meta = $trait->classDeprecated;
        if (null === $meta) {
            return;
        }
        $message = $meta->formatTraitUse($trait->name, $user->name);
        $file = $user->sourceLocation?->filename;
        $line = $user->sourceLocation?->startLine ?? 0;
        if ((null === $file || '' === $file) && null !== $frame && '' !== $frame->scriptPath) {
            $file = $frame->scriptPath;
        }
        $this->context->errors->triggerError(
            $message,
            ErrorReporter::E_USER_DEPRECATED,
            (null !== $file && '' !== $file) ? $file : null,
            $this->context,
            $frame,
            $line > 0 ? $line : 0
        );
    }

    private function emitGlobalConstFetchDeprecation(string $constName, Frame $frame): void
    {
        $meta = $this->context->globalConstDeprecated[strtolower($constName)] ?? null;
        if (null === $meta || !$meta->emitsRuntimeNotice()) {
            return;
        }
        $this->emitDeprecatedNotice($meta->formatGlobalConstant($constName), $frame);
    }

    private function emitClassConstFetchDeprecation(
        ClassEntry $classEntry,
        string $memberNameRaw,
        string $memberLc,
        Frame $frame
    ): void {
        if ($classEntry->isEnum) {
            if (null !== $classEntry->classDeprecated && $classEntry->classDeprecated->emitsRuntimeNotice()) {
                $this->emitDeprecatedNotice(
                    $classEntry->classDeprecated->formatEnum($classEntry->name),
                    $frame
                );
            }
            if (isset($classEntry->constDeprecated[$memberLc])) {
                $meta = $classEntry->constDeprecated[$memberLc];
                if ($meta->emitsRuntimeNotice()) {
                    $this->emitDeprecatedNotice(
                        $meta->formatEnumCase($classEntry->name, $memberNameRaw),
                        $frame
                    );
                }
            }

            return;
        }
        if (isset($classEntry->constDeprecated[$memberLc])) {
            $meta = $classEntry->constDeprecated[$memberLc];
            if ($meta->emitsRuntimeNotice()) {
                // Zend cites the declaring class/interface (A::X / I::X), not the fetch class (#29380).
                $this->emitDeprecatedNotice(
                    $meta->formatConstant(
                        $this->classConstDeprecatedOwnerDisplay($classEntry, $memberLc),
                        $memberNameRaw
                    ),
                    $frame
                );
            }
        }
    }

    /** Declaring class/interface display name for class-const #[\Deprecated] notices (#29380). */
    private function classConstDeprecatedOwnerDisplay(ClassEntry $classEntry, string $memberLc): string
    {
        $declLc = $classEntry->constDeclaringClassLc[$memberLc] ?? null;
        if (null !== $declLc && isset($this->context->classes[$declLc])) {
            return $this->context->classes[$declLc]->name;
        }

        return $classEntry->name;
    }

    private function emitInstancePropertyAccessDeprecation(
        ObjectEntry $object,
        string $propName,
        Frame $frame
    ): void {
        $meta = $this->classPropertyMeta($object, $propName);
        if (null === $meta) {
            return;
        }
        $declLc = '' !== $meta->declaringClassLc
            ? $meta->declaringClassLc
            : strtolower($object->class->name);
        if (!isset($this->context->classes[$declLc])) {
            return;
        }
        $declEntry = $this->context->classes[$declLc];
        $propLc = strtolower($propName);
        if (!isset($declEntry->propDeprecated[$propLc])) {
            return;
        }
        // Property targets: attribute presence is enough (bare #[\Deprecated] still emits —
        // Zend zend_object_handlers.c / #23536; same rule as call sites #27825).
        $meta = $declEntry->propDeprecated[$propLc];
        $this->emitDeprecatedNotice(
            $meta->formatProperty($declEntry->name, $propName),
            $frame
        );
    }

    private function emitStaticPropertyAccessDeprecation(
        string $classLc,
        string $propNameRaw,
        Frame $frame
    ): void {
        $meta = $this->resolveStaticPropertyVisibilityMeta($classLc, strtolower($propNameRaw));
        if (null === $meta) {
            return;
        }
        $declLc = $meta['declaringClassLc'];
        if (!isset($this->context->classes[$declLc])) {
            return;
        }
        $declEntry = $this->context->classes[$declLc];
        $propLc = strtolower($propNameRaw);
        if (!isset($declEntry->propDeprecated[$propLc])) {
            return;
        }
        $depMeta = $declEntry->propDeprecated[$propLc];
        $this->emitDeprecatedNotice(
            $depMeta->formatProperty($meta['declaringClassDisplay'], $propNameRaw),
            $frame
        );
    }

    private function emitPropertyWriteDeprecation(Variable $lvalue, Frame $frame): void
    {
        $target = $lvalue->resolveIndirect();
        if (null !== $target->objectPropertyOwner && null !== $target->objectPropertyName) {
            $this->emitInstancePropertyAccessDeprecation(
                $target->objectPropertyOwner,
                $target->objectPropertyName,
                $frame
            );

            return;
        }
        $classLc = $target->staticPropertyClassLc ?? $lvalue->staticPropertyClassLc;
        $propName = $target->objectPropertyName ?? $lvalue->objectPropertyName;
        if (is_string($classLc) && is_string($propName) && '' !== $propName) {
            $this->emitStaticPropertyAccessDeprecation($classLc, $propName, $frame);
        }
    }
}
