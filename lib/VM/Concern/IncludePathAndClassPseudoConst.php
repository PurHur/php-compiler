<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;

/**
 * Include path resolve + class pseudo-const display for the VM (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code resolveIncludeFilename} through
 * {@code resolveClassPseudoConstFromOperand} (php-src Zend/zend_execute.c include /
 * stream open path search; Zend/zend_constants.c / zend_compile.c `::class` and
 * self/parent/static display names — #3223, #6051, #15645, #29623). Concern trait —
 * same namespace as parent so relative Frame / Block helpers resolve. Move-only; no
 * new C ABI.
 */
trait IncludePathAndClassPseudoConst
{
    private function resolveIncludeFilename(string $file, Frame $frame): ?string
    {
        if ('' === $file || str_contains($file, "\0")) {
            return null;
        }
        // Absolute unix paths or windows drive letters.
        if ($file[0] === '/' || (strlen($file) > 1 && $file[1] === ':')) {
            $normalized = VM\ScriptStack::normalize($file);

            return '' !== $normalized && is_file($normalized) ? $normalized : null;
        }

        // 1) As-is (cwd / relative execution context)
        $candidate = VM\ScriptStack::normalize($file);
        if ('' !== $candidate && is_file($candidate)) {
            return $candidate;
        }

        // 2) Relative to the current script directory (Zend-like common path)
        $current = '' !== $frame->scriptPath ? $frame->scriptPath : $this->context->scriptStack->current();
        if (!is_string($current) || '' === $current || '-' === $current) {
            $current = '';
        }
        if ('' !== $current) {
            $fromDir = dirname($current);
            $cand = VM\ScriptStack::normalize($fromDir.'/'.$file);
            if ('' !== $cand && is_file($cand)) {
                return $cand;
            }
        }

        // 3) include_path search (VmIncludePath stack; issues #3223, #6051)
        $includePath = \PHPCompiler\ext\standard\VmIncludePath::get();
        if ('' !== $includePath) {
            foreach (explode(\PATH_SEPARATOR, $includePath) as $dir) {
                if ('' === $dir) {
                    continue;
                }
                $cand = VM\ScriptStack::normalize(rtrim($dir, '/').'/'.$file);
                if ('' !== $cand && is_file($cand)) {
                    return $cand;
                }
            }
        }

        return null;
    }

    /**
     * Zend {@code zend_zval_value_name()} labels for method-on-non-object Errors (#4241, #30054).
     *
     * Booleans use {@code true}/{@code false}, not {@code bool} (distinct from TypeError
     * {@code zend_zval_type_name} spelling gated in {@see VM\EnumCaseSupport::typeNameForTypeErrorActual()}).
     */
    private function valueDebugTypeLabel(Variable $value): string
    {
        if (Variable::TYPE_OBJECT === $value->type || Variable::TYPE_ENUM_CASE === $value->type) {
            return 'object';
        }

        return VM\EnumCaseSupport::typeNameForZvalValueName($value);
    }

    /**
     * Resolve `ClassName::class` reference string (Zend zend_constants.c; #15645).
     *
     * Returns the name used to refer to the class — alias when accessed via alias, canonical otherwise.
     * self/parent/static resolve to the declaring/late-static/parent class canonical name.
     */
    protected function resolveClassPseudoConstDisplayName(string $className, Frame $frame): string
    {
        $lcClass = strtolower($className);
        if ('self' === $lcClass) {
            $declaring = $this->declaringClassLc($frame, 'self');
            $fallback = $this->context->classes[$declaring]->name;
            $funcClassLc = null;
            $funcIsTrait = false;
            $methodLc = null;
            if (null !== $frame->block->func && null !== $frame->block->func->class) {
                $funcClassLc = $frame->block->func->class->value;
                $funcLc = strtolower(ltrim($funcClassLc, '\\'));
                $funcIsTrait = ($this->context->classes[$funcLc] ?? null)?->isTrait ?? false;
                $methodLc = strtolower($frame->block->func->name);
            }
            $calledLc = null !== $frame->calledClass && '' !== $frame->calledClass
                ? $frame->calledClass
                : null;

            return VM\TraitSelfClassScope::resolveSelfClassName(
                $funcClassLc,
                $funcIsTrait,
                $calledLc,
                $fallback,
                fn (string $lc): string => $this->context->classes[$lc]->name,
                $methodLc,
                fn (string $classLc, string $method): ?string => $this->context->classes[$classLc]->traitMethodSources[$method] ?? null,
                fn (string $classLc): ?string => $this->context->classes[$classLc]->parentLc ?? null,
                fn (string $classLc): bool => ($this->context->classes[$classLc] ?? null)?->isTrait ?? false,
            );
        }
        if ('static' === $lcClass) {
            $lateLc = $this->lateStaticClassLc($frame);

            return $this->context->classes[$lateLc]->name;
        }
        if ('parent' === $lcClass) {
            $declaring = $this->declaringClassLc($frame, 'parent');
            $parentLc = $this->context->classes[$declaring]->parentLc;
            if (null === $parentLc) {
                throw new \LogicException('parent:: used when class has no parent');
            }

            return $this->context->classes[$parentLc]->name;
        }

        return $className;
    }

    /**
     * Resolve `$operand::class` (Zend zend_compile.c FETCH_CLASS on enum case / object).
     *
     * Legacy Resource wrappers are not objects for {@code ::class} — Zend TypeError
     * {@code Cannot use "::class" on resource} (#29623 / zend_execute.c).
     */
    private function resolveClassPseudoConstFromOperand(Variable $operand): ?string
    {
        $operand = $operand->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $operand->type) {
            return $operand->toEnumCase()->enumClass->name;
        }
        if (Variable::TYPE_OBJECT === $operand->type) {
            $object = $operand->toObject();
            if (VM\ResourceSupport::isHiddenPseudoClassEntry($object->class)) {
                return null;
            }

            return $object->class->name;
        }

        return null;
    }
}
