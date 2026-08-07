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
use PhpParser\Node\Stmt\Class_;

/**
 * Compile-time check: non-final classes cannot extend a final parent (#3406, #21669).
 *
 * php-src: Zend/zend_compile.c — zend_compile_class_decl;
 * Zend/zend_inheritance.c — do_inheritance_on_class
 * Enums are implicitly final (ZEND_ACC_FINAL; #26531).
 */
final class FinalClassExtensionCheck
{
    /**
     * Builtin final classes not present in the script AST (ZEND_ACC_FINAL).
     *
     * @var array<string, string> lowercase name => display name
     */
    private const INTERNAL_FINAL = [
        // php-src ext/sockets/sockets.stub.php — final class Socket / AddressInfo (#28391).
        'addressinfo' => 'AddressInfo',
        'attribute' => 'Attribute',
        // php-src Zend/zend_attributes.stub.php — final builtin attribute classes (#28402).
        'allowdynamicproperties' => 'AllowDynamicProperties',
        'closure' => 'Closure',
        // php-src ext/zlib/zlib.stub.php — final class DeflateContext / InflateContext (#28385).
        'deflatecontext' => 'DeflateContext',
        'fiber' => 'Fiber',
        'fibererror' => 'FiberError',
        'generator' => 'Generator',
        'inflatecontext' => 'InflateContext',
        // php-src ext/random/random.stub.php — final Randomizer + Engine\* (#28387).
        'random\\engine\\mt19937' => 'Random\\Engine\\Mt19937',
        'random\\engine\\pcgoneseq128xslrr64' => 'Random\\Engine\\PcgOneseq128XslRr64',
        'random\\engine\\secure' => 'Random\\Engine\\Secure',
        'random\\engine\\xoshiro256starstar' => 'Random\\Engine\\Xoshiro256StarStar',
        'random\\randomizer' => 'Random\\Randomizer',
        'returntypewillchange' => 'ReturnTypeWillChange',
        'sensitiveparameter' => 'SensitiveParameter',
        'socket' => 'Socket',
        // php-src Zend/zend_weakrefs.stub.php — final class WeakReference / WeakMap (#28390).
        'weakmap' => 'WeakMap',
        'weakreference' => 'WeakReference',
        // php-src ext/xml/xml.stub.php — final class XMLParser (#28386).
        'xmlparser' => 'XMLParser',
    ];

    /** @var array<string, array{display: string, final: bool, extends: ?string}> */
    private array $classes = [];

    /** @var array<string, string> lowercase enum name => display name (implicitly final) */
    private array $enums = [];

    public static function validate(Script $script): void
    {
        $check = new self();
        $check->collect($script);
        $check->verify();
    }

    private function collect(Script $script): void
    {
        // php-cfg nests declarations after try/catch merge blocks — not only main->cfg children (#9722).
        $finder = new DeclarationFinder();
        $traverser = new Traverser();
        $traverser->addVisitor($finder);
        $traverser->traverse($script);
        foreach ($finder->getClasses() as $class) {
            $this->collectClass($class);
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

    private function collectClass(Op\Stmt\Class_ $class): void
    {
        $lc = $this->operandLcName($class->name);
        if (null === $lc) {
            return;
        }
        $parentLc = null;
        if (null !== $class->extends) {
            $parentLc = $this->operandLcName($class->extends);
        }
        $this->classes[$lc] = [
            'display' => $this->operandDisplayName($class->name, $lc),
            'final' => 0 !== ($class->flags & Class_::MODIFIER_FINAL),
            'extends' => $parentLc,
        ];
    }

    private function collectEnum(Op\Stmt\Enum_ $enum): void
    {
        $lc = $this->operandLcName($enum->name);
        if (null === $lc) {
            return;
        }
        $this->enums[$lc] = $this->operandDisplayName($enum->name, $lc);
    }

    private function verify(): void
    {
        foreach ($this->classes as $class) {
            $parentLc = $class['extends'];
            if (null === $parentLc) {
                continue;
            }
            $parentDisplay = $this->finalParentDisplay($parentLc);
            if (null === $parentDisplay) {
                continue;
            }
            throw new \CompileError(
                "Class {$class['display']} cannot extend final class {$parentDisplay}"
            );
        }
    }

    private function finalParentDisplay(string $parentLc): ?string
    {
        if (isset($this->enums[$parentLc])) {
            return $this->enums[$parentLc];
        }
        if (isset($this->classes[$parentLc])) {
            return $this->classes[$parentLc]['final']
                ? $this->classes[$parentLc]['display']
                : null;
        }
        if (isset(self::INTERNAL_FINAL[$parentLc])) {
            return self::INTERNAL_FINAL[$parentLc];
        }
        // php-src 8.4+ `final class GMP` (ext/gmp/gmp.stub.php; #28135) — not in script AST.
        if ('gmp' === $parentLc && \PHPCompiler\CompilerVersion::supportsGmp()) {
            return 'GMP';
        }
        // php-src Zend/zend_attributes.stub.php — profile-gated final attribute classes (#28402).
        if ('override' === $parentLc && \PHPCompiler\CompilerVersion::advertisesOverrideAttributeClass()) {
            return 'Override';
        }
        if ('deprecated' === $parentLc && \PHPCompiler\CompilerVersion::advertisesDeprecatedAttributeClass()) {
            return 'Deprecated';
        }
        if ('nodiscard' === $parentLc && \PHPCompiler\CompilerVersion::advertisesNoDiscardAttributeClass()) {
            return 'NoDiscard';
        }

        return null;
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
        $short = $name;
        if (str_contains($name, '\\')) {
            $parts = explode('\\', ltrim($name, '\\'));
            $short = end($parts) ?: $name;
        }

        return $short;
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
