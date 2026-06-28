<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\AbstractVisitor;
use PHPCfg\Block;
use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Script;
use PHPCfg\Traverser;
use PHPCfg\Visitor\DeclarationFinder;

/**
 * Compile-time check: implements/extends targets must match Zend hierarchy rules (#12971).
 *
 * php-src: Zend/zend_compile.c — zend_do_implement_interface(), zend_do_inheritance()
 */
final class ImplementsHierarchyCompileCheck
{
    /** @var array<string, string> lc => display */
    private array $interfaces = [];

    /** @var array<string, string> lc => display (class, enum, trait) */
    private array $nonInterfaces = [];

    /** @var array<string, array{display: string, implements: list<string>, extends: ?string}> */
    private array $classes = [];

    /** @var array<string, array{display: string, implements: list<string>}> */
    private array $enums = [];

    /** @var array<string, array{display: string, extends: list<string>}> */
    private array $interfaceExtends = [];

    public static function validate(Script $script): void
    {
        $check = new self();
        $check->collect($script);
        $check->verify();
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
        foreach ($finder->getClasses() as $class) {
            $this->collectClass($class);
        }
        foreach ($finder->getTraits() as $trait) {
            $this->collectTrait($trait);
        }

        $enumCollector = new class extends AbstractVisitor {
            /** @var list<Op\Stmt\Enum_> */
            public array $enums = [];

            public function enterOp(Op $op, Block $block): void
            {
                if ($op instanceof Op\Stmt\Enum_) {
                    $this->enums[] = $op;
                }
            }
        };
        $enumTraverser = new Traverser();
        $enumTraverser->addVisitor($enumCollector);
        $enumTraverser->traverse($script);
        foreach ($enumCollector->enums as $enum) {
            $this->collectEnum($enum);
        }
    }

    private function collectInterface(Op\Stmt\Interface_ $iface): void
    {
        $lc = $this->operandLcName($iface->name);
        if (null === $lc) {
            return;
        }
        $display = $this->operandDisplayName($iface->name, $lc);
        $this->interfaces[$lc] = $display;
        $extends = [];
        foreach ($iface->extends as $parentOperand) {
            $parentLc = $this->operandLcName($parentOperand);
            if (null !== $parentLc) {
                $extends[] = $parentLc;
            }
        }
        $this->interfaceExtends[$lc] = [
            'display' => $display,
            'extends' => $extends,
        ];
    }

    private function collectClass(Op\Stmt\Class_ $class): void
    {
        $lc = $this->operandLcName($class->name);
        if (null === $lc) {
            return;
        }
        $display = $this->operandDisplayName($class->name, $lc);
        $this->nonInterfaces[$lc] = $display;
        $implements = [];
        foreach ($class->implements as $ifaceOperand) {
            $ifaceLc = $this->operandLcName($ifaceOperand);
            if (null !== $ifaceLc) {
                $implements[] = $ifaceLc;
            }
        }
        $extendsLc = null;
        if (null !== $class->extends) {
            $extendsLc = $this->operandLcName($class->extends);
        }
        $this->classes[$lc] = [
            'display' => $display,
            'implements' => $implements,
            'extends' => $extendsLc,
        ];
    }

    private function collectEnum(Op\Stmt\Enum_ $enum): void
    {
        $lc = $this->operandLcName($enum->name);
        if (null === $lc) {
            return;
        }
        $display = $this->operandDisplayName($enum->name, $lc);
        $this->nonInterfaces[$lc] = $display;
        $implements = [];
        foreach ($enum->implements as $ifaceOperand) {
            $ifaceLc = $this->operandLcName($ifaceOperand);
            if (null !== $ifaceLc) {
                $implements[] = $ifaceLc;
            }
        }
        $this->enums[$lc] = [
            'display' => $display,
            'implements' => $implements,
        ];
    }

    private function collectTrait(Op\Stmt\Trait_ $trait): void
    {
        $lc = $this->operandLcName($trait->name);
        if (null === $lc) {
            return;
        }
        $this->nonInterfaces[$lc] = $this->operandDisplayName($trait->name, $lc);
    }

    private function verify(): void
    {
        foreach ($this->classes as $class) {
            foreach ($class['implements'] as $targetLc) {
                if (isset($this->nonInterfaces[$targetLc])) {
                    throw new \CompileError(sprintf(
                        '%s cannot implement %s - it is not an interface',
                        $class['display'],
                        $this->nonInterfaces[$targetLc]
                    ));
                }
            }
            $extendsLc = $class['extends'];
            if (null !== $extendsLc && isset($this->interfaces[$extendsLc])) {
                throw new \CompileError(sprintf(
                    'Class %s cannot extend interface %s',
                    $class['display'],
                    $this->interfaces[$extendsLc]
                ));
            }
        }

        foreach ($this->enums as $enum) {
            foreach ($enum['implements'] as $targetLc) {
                if (isset($this->nonInterfaces[$targetLc])) {
                    throw new \CompileError(sprintf(
                        '%s cannot implement %s - it is not an interface',
                        $enum['display'],
                        $this->nonInterfaces[$targetLc]
                    ));
                }
            }
        }

        foreach ($this->interfaceExtends as $iface) {
            foreach ($iface['extends'] as $targetLc) {
                if (isset($this->nonInterfaces[$targetLc])) {
                    throw new \CompileError(sprintf(
                        '%s cannot implement %s - it is not an interface',
                        $iface['display'],
                        $this->nonInterfaces[$targetLc]
                    ));
                }
            }
        }
    }

    private function operandLcName(Operand $op): ?string
    {
        $name = $this->staticNameFromOperand($op);
        if (null === $name) {
            return null;
        }

        return strtolower(ltrim($name, '\\'));
    }

    private function operandDisplayName(Operand $op, string $fallbackLc): string
    {
        $name = $this->staticNameFromOperand($op);
        if (null === $name) {
            return $fallbackLc;
        }
        if (str_contains($name, '\\')) {
            return ltrim($name, '\\');
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
