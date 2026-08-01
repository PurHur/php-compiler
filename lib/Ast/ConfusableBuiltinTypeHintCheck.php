<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UnionType;
use PhpParser\NodeVisitorAbstract;
use PHPCompiler\Block;
use PHPCompiler\Frame;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\VM;
use PHPCompiler\VM\Context as VMContext;

/**
 * Zend confusable / unsupported-builtin type-name warnings (#26639).
 *
 * php-src: Zend/zend_compile.c — zend_is_confusable_type() + ZEND_NAME_NOT_FQ path in
 * zend_compile_single_typename(). Bare lowercase boolean/integer/double/resource warn;
 * `\Name` and `use Name` suppress. Must run **before** {@see MultiBlockNameResolver}.
 */
final class ConfusableBuiltinTypeHintCheck extends NodeVisitorAbstract
{
    /** @var array<string, string|null> lowercase spelling → preferred builtin (null = none) */
    private const CONFUSABLE = [
        'boolean' => 'bool',
        'integer' => 'int',
        'double' => 'float',
        'resource' => null,
    ];

    /** @var list<array{message: string, line: int}> */
    private array $pending = [];

    /** Current namespace without leading backslash ('' = global). */
    private string $namespace = '';

    /** @var array<string, true> lowercase unqualified import aliases currently in scope */
    private array $imports = [];

    public function beforeTraverse(array $nodes)
    {
        $this->pending = [];
        $this->namespace = '';
        $this->imports = [];

        return null;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Namespace_) {
            $this->namespace = null !== $node->name ? $node->name->toString() : '';
            $this->imports = [];

            return null;
        }
        if ($node instanceof Use_) {
            $this->recordUse($node);

            return null;
        }

        if ($node instanceof Node\Stmt\Function_
            || $node instanceof Node\Stmt\ClassMethod
            || $node instanceof Node\Expr\Closure
            || $node instanceof Node\Expr\ArrowFunction
        ) {
            foreach ($node->params as $param) {
                $this->checkTypeNode($param->type);
            }
            $this->checkTypeNode($node->returnType ?? null);

            return null;
        }
        if ($node instanceof Node\Stmt\Property) {
            $this->checkTypeNode($node->type);

            return null;
        }
        if ($node instanceof Node\Stmt\ClassConst) {
            $this->checkTypeNode($node->type ?? null);

            return null;
        }

        return null;
    }

    /**
     * Flush warnings collected during the last AST traverse (after CFG parse).
     */
    public function emitPending(VMContext $context, string $filename): void
    {
        if ([] === $this->pending) {
            return;
        }
        if (NestedJitCompileScope::isActive()) {
            $this->pending = [];

            return;
        }
        $frame = $this->resolveEmitFrame($context, $filename);
        foreach ($this->pending as $entry) {
            $context->errors->languageWarning(
                $entry['message'],
                $filename,
                $entry['line'],
                $context,
                $frame
            );
        }
        $this->pending = [];
    }

    public function clearPending(): void
    {
        $this->pending = [];
    }

    private function recordUse(Use_ $use): void
    {
        // Only normal class/namespace imports participate in zend_is_not_imported().
        if (Use_::TYPE_FUNCTION === $use->type || Use_::TYPE_CONSTANT === $use->type) {
            return;
        }
        foreach ($use->uses as $useUse) {
            $alias = null !== $useUse->alias
                ? $useUse->alias->toString()
                : $useUse->name->getLast();
            $this->imports[strtolower($alias)] = true;
        }
    }

    private function checkTypeNode(Node|Identifier|Name|ComplexType|null $type): void
    {
        if (null === $type || $type instanceof Identifier) {
            return;
        }
        if ($type instanceof NullableType) {
            $this->checkTypeNode($type->type);

            return;
        }
        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            foreach ($type->types as $member) {
                $this->checkTypeNode($member);
            }

            return;
        }
        if (!$type instanceof Name) {
            return;
        }
        // ZEND_NAME_NOT_FQ only — FullyQualified / Relative suppress the warning.
        if ($type->isFullyQualified() || $type->isRelative()) {
            return;
        }
        $parts = $type->getParts();
        if (1 !== \count($parts)) {
            return;
        }
        $spelling = $parts[0];
        if (!\array_key_exists($spelling, self::CONFUSABLE)) {
            return;
        }
        // Case-sensitive: "Integer" is a class name, "integer" is confusable.
        if (isset($this->imports[strtolower($spelling)])) {
            return;
        }
        $correct = self::CONFUSABLE[$spelling];
        $resolvedClass = '' === $this->namespace
            ? $spelling
            : $this->namespace.'\\'.$spelling;
        $extra = '' === $this->namespace ? '' : ' or import the class with "use"';
        if (null !== $correct) {
            $message = sprintf(
                '"%s" will be interpreted as a class name. Did you mean "%s"? Write "\\%s"%s to suppress this warning',
                $spelling,
                $correct,
                $resolvedClass,
                $extra
            );
        } else {
            $message = sprintf(
                '"%s" is not a supported builtin type and will be interpreted as a class name. Write "\\%s"%s to suppress this warning',
                $spelling,
                $resolvedClass,
                $extra
            );
        }
        $this->pending[] = [
            'message' => $message,
            'line' => max(0, $type->getStartLine()),
        ];
    }

    private function resolveEmitFrame(VMContext $context, string $filename): Frame
    {
        $vm = VM::running();
        if ($vm instanceof VM) {
            $frame = $vm->builtinHandlerFrame();
            if (null !== $frame) {
                return $frame;
            }
            $frames = $context->runStackFrames();
            if ([] !== $frames) {
                return $frames[0];
            }
        }

        $block = new Block(null);
        $frame = new Frame(null, $block, null);
        $frame->vmContext = $context;
        $frame->scriptPath = $filename;

        return $frame;
    }
}
