<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;
use PHPCfg\Traverser;
use PHPCfg\Visitor\DeclarationFinder;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context as VMContext;

/**
 * Compile-time check: class implementing two interfaces with the same constant name
 * is ambiguous — Zend E_COMPILE_ERROR (#24699, Zend/zend_inheritance.c).
 *
 * php-src: Zend/zend_inheritance.c — do_inherit_iface_constant()
 *
 * Same-script units are covered by collecting interface decls from the CFG. Eval and
 * cross-file `require` units resolve already-declared interfaces via {@see VMContext}
 * (#26672) — the same ClassEntry table Runtime::compile() passes into the compiler.
 */
final class InterfaceConstAmbiguityCheck
{
    /** @var array<string, list<string>> lc-interface-name => list of constant names */
    private array $interfaceConstants = [];

    /** @var array<string, string> lc => display name */
    private array $interfaceDisplayNames = [];

    public function __construct(
        private readonly ?VMContext $vmContext = null,
    ) {
    }

    public static function validate(Script $script, ?VMContext $vmContext = null): void
    {
        $check = new self($vmContext);
        $check->collect($script);
        $check->verify($script);
    }

    private function collect(Script $script): void
    {
        $finder = new DeclarationFinder();
        $traverser = new Traverser();
        $traverser->addVisitor($finder);
        $traverser->traverse($script);

        foreach ($finder->getInterfaces() as $iface) {
            $this->collectInterface($iface);
        }
    }

    private function collectInterface(Op\Stmt\Interface_ $iface): void
    {
        $lc = $this->operandLcName($iface->name);
        if (null === $lc) {
            return;
        }
        $display = $this->operandDisplayName($iface->name, $lc);
        $this->interfaceDisplayNames[$lc] = $display;

        $constants = [];
        foreach ($iface->stmts->children as $member) {
            if (!$member instanceof Op\Terminal\Const_) {
                continue;
            }
            $constName = $this->staticNameFromOperand($member->name);
            if (null !== $constName) {
                $constants[] = $constName;
            }
        }
        $this->interfaceConstants[$lc] = $constants;
    }

    private function verify(Script $script): void
    {
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof Op\Stmt\Class_) {
                $this->verifyClass($child);
            } elseif ($child instanceof Op\Stmt\Enum_) {
                $this->verifyEnum($child);
            }
        }
    }

    private function verifyClass(Op\Stmt\Class_ $class): void
    {
        $classDisplay = $this->operandDisplayName($class->name, 'class');
        $this->checkImplementsForAmbiguity($class->implements, 'Class', $classDisplay, $class);
    }

    private function verifyEnum(Op\Stmt\Enum_ $enum): void
    {
        $enumDisplay = $this->operandDisplayName($enum->name, 'enum');
        $this->checkImplementsForAmbiguity($enum->implements, 'Enum', $enumDisplay, $enum);
    }

    /**
     * @param list<Operand> $implements
     */
    private function checkImplementsForAmbiguity(
        array $implements,
        string $subjectKind,
        string $subjectDisplay,
        Op $subject
    ): void {
        /** @var array<string, string> constant-name => declaring-interface-display-name (first seen) */
        $seen = [];

        foreach ($implements as $ifaceOperand) {
            $ifaceLc = $this->operandLcName($ifaceOperand);
            if (null === $ifaceLc) {
                continue;
            }

            foreach ($this->constantsForInterface($ifaceLc) as [$constName, $declaringDisplay]) {
                if (isset($seen[$constName])) {
                    throw new CompileFatal(
                        $subject->getFile(),
                        $subject->getLine(),
                        sprintf(
                            '%s %s inherits both %s::%s and %s::%s, which is ambiguous',
                            $subjectKind,
                            $subjectDisplay,
                            $seen[$constName],
                            $constName,
                            $declaringDisplay,
                            $constName
                        )
                    );
                }
                $seen[$constName] = $declaringDisplay;
            }
        }
    }

    /**
     * Direct script-local interface constants, else already-declared ClassEntry constants (#26672).
     *
     * @return list<array{0: string, 1: string}> list of [constName, declaringInterfaceDisplay]
     */
    private function constantsForInterface(string $ifaceLc): array
    {
        if (array_key_exists($ifaceLc, $this->interfaceConstants)) {
            $ifaceDisplay = $this->interfaceDisplayNames[$ifaceLc] ?? $ifaceLc;
            $out = [];
            foreach ($this->interfaceConstants[$ifaceLc] as $constName) {
                $out[] = [$constName, $ifaceDisplay];
            }

            return $out;
        }

        $entry = $this->vmContext?->classes[$ifaceLc] ?? null;
        if (!$entry instanceof ClassEntry || !$entry->isInterface) {
            return [];
        }

        $out = [];
        foreach ($entry->constants as $constKey => $_) {
            $constName = $entry->constNames[$constKey] ?? (string) $constKey;
            $declLc = $entry->constDeclaringClassLc[$constKey] ?? $ifaceLc;
            $out[] = [$constName, $this->displayNameForLc($declLc)];
        }

        return $out;
    }

    private function displayNameForLc(string $lc): string
    {
        if (isset($this->interfaceDisplayNames[$lc])) {
            return $this->interfaceDisplayNames[$lc];
        }
        $entry = $this->vmContext?->classes[$lc] ?? null;
        if ($entry instanceof ClassEntry) {
            return $this->classEntryDisplayName($entry);
        }

        return $lc;
    }

    private function classEntryDisplayName(ClassEntry $entry): string
    {
        $name = $entry->name;
        if (str_contains($name, '\\')) {
            $parts = explode('\\', ltrim($name, '\\'));

            return end($parts) ?: $name;
        }

        return $name;
    }

    private function operandLcName(Operand $op): ?string
    {
        $name = $this->staticNameFromOperand($op);
        if (null === $name) {
            return null;
        }

        return strtolower(ltrim($name, '\\'));
    }

    private function operandDisplayName(Operand $op, string $fallback): string
    {
        $name = $this->staticNameFromOperand($op);
        if (null === $name) {
            return $fallback;
        }
        if (str_contains($name, '\\')) {
            $parts = explode('\\', ltrim($name, '\\'));

            return end($parts) ?: $name;
        }

        return $name;
    }

    private function staticNameFromOperand(Operand $op): ?string
    {
        if ($op instanceof Operand\Literal && is_string($op->value)) {
            return $op->value;
        }
        if ($op instanceof Operand\Variable) {
            return $this->staticNameFromOperand($op->name);
        }

        return null;
    }
}
