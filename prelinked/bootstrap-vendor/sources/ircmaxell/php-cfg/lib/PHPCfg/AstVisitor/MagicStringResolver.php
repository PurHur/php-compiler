<?php

declare(strict_types=1);

/**
 * This file is part of PHP-CFG, a Control flow graph implementation for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCfg\AstVisitor;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class MagicStringResolver extends NodeVisitorAbstract
{
    private const PROPERTY_GET_HOOK_PREFIX = '__phpc_property_get_';

    private const PROPERTY_SET_HOOK_PREFIX = '__phpc_property_set_';

    /** Zend/zend_compile.c — __PROPERTY__ (T_PROPERTY_C) requires active property hook (#18815, re-#5978). */
    private const PROPERTY_MAGIC_OUTSIDE_HOOK = 'Cannot use __PROPERTY__ outside of a property hook';

    protected $classStack = [];

    protected $parentStack = [];

    protected $functionStack = [];

    protected $methodStack = [];

    /** @var list<string> */
    protected $propertyStack = [];

    /** @var list<string> */
    protected $namespaceStack = [];

    /** @var list<string> */
    protected $traitStack = [];

    /** True while visiting StaticCall::class — preserve `parent` for runtime dispatch (#6735). */
    protected bool $inStaticCallClassName = false;

    private const PRESERVE_LEXICAL_TYPE = 'phpcPreserveLexicalType';

    /** @var string */
    protected $compilationUnitFile = '';

    /** @var int */
    protected $anonymousClassCounter = 0;

    public function beginCompilationUnit(string $fileName): void
    {
        if ('' !== $fileName && is_file($fileName)) {
            $real = realpath($fileName);
            if (false !== $real) {
                $fileName = $real;
            }
        }
        $this->compilationUnitFile = $fileName;
        $this->anonymousClassCounter = 0;
    }

    public function enterNode(Node $node)
    {
        $this->repairComments($node);
        if ($node instanceof Node\Stmt\Namespace_) {
            $name = '';
            if (null !== $node->name) {
                $name = $node->name->toString();
            }
            $this->namespaceStack[] = $name;
        }
        if ($node instanceof Node\Stmt\ClassLike) {
            if (null === $node->name) {
                $node->namespacedName = new Node\Name\FullyQualified(
                    $this->anonymousClassName($node),
                    $node->getAttributes()
                );
            }
            $this->classStack[] = $node->namespacedName->toString();
            if ($node instanceof Node\Stmt\Trait_) {
                $this->traitStack[] = $node->namespacedName->toString();
            }
            if (! empty($node->extends) && ! is_array($node->extends)) {
                // Should always be fully qualified
                $this->parentStack[] = $node->extends->toString();
            } else {
                $this->parentStack[] = '';
            }
        }
        $this->repairComments($node);
        if ($node instanceof Node\Stmt\Function_) {
            $this->functionStack[] = $node->namespacedName->toString();
            if (null !== $node->returnType) {
                $this->markTypeHintPreserveLexical($node->returnType);
            }
            foreach ($node->params as $param) {
                if (null !== $param->type) {
                    $this->markTypeHintPreserveLexical($param->type);
                }
            }
        } elseif ($node instanceof Node\Stmt\ClassMethod) {
            $this->methodStack[] = end($this->classStack).'::'.$node->name;
            $prop = $this->propertyNameFromHookMethod($node->name->name);
            if (null !== $prop) {
                $this->propertyStack[] = $prop;
            }
            if (null !== $node->returnType) {
                $this->markTypeHintPreserveLexical($node->returnType);
            }
            foreach ($node->params as $param) {
                if (null !== $param->type) {
                    $this->markTypeHintPreserveLexical($param->type);
                }
            }
        } elseif ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            // Zend/zend_compile.c — T_FUNC_C / T_METHOD_C inside closures are "{closure}" (#22832).
            // Push on both stacks so a method-nested closure does not inherit enclosing names.
            $this->functionStack[] = '{closure}';
            $this->methodStack[] = '{closure}';
            if (null !== $node->returnType) {
                $this->markTypeHintPreserveLexical($node->returnType);
            }
            foreach ($node->params as $param) {
                if (null !== $param->type) {
                    $this->markTypeHintPreserveLexical($param->type);
                }
            }
        } elseif ($node instanceof Node\Stmt\Property) {
            if (null !== $node->type) {
                $this->markTypeHintPreserveLexical($node->type);
            }
        } elseif ($node instanceof Node\Stmt\ClassConst) {
            if (null !== $node->type) {
                $this->markTypeHintPreserveLexical($node->type);
            }
        } elseif ($node instanceof Node\Expr\StaticCall) {
            $this->inStaticCallClassName = true;
        } elseif ($node instanceof Node\Expr\ConstFetch) {
            if ('__property__' === strtolower($node->name->toString())) {
                if ($this->propertyStack === []) {
                    if ($this->propertyHooksProfileEnabled()) {
                        throw new \CompileError(self::PROPERTY_MAGIC_OUTSIDE_HOOK);
                    }

                    // Default profile: leave ConstFetch — runtime Undefined constant (Zend 8.2+, #18900).
                    return null;
                }

                return new Node\Scalar\String_(end($this->propertyStack), $node->getAttributes());
            }
        } elseif ($node instanceof Node\Name) {
            if ($node->getAttribute(self::PRESERVE_LEXICAL_TYPE)) {
                return null;
            }
            switch (strtolower($node->toString())) {
                case 'self':
                    // Keep lexical `self` inside traits so VM/JIT bind to the composing class
                    // (#19629, #18879, Zend/zend_traits.c) — methods, constants, and ::class.
                    if ([] !== $this->traitStack) {
                        break;
                    }
                    // Preserve `self` on StaticCall class so late-static scope is not clobbered
                    // the way a named ClassName::call would be (#21983, peer of parent #6735/#12245).
                    if ($this->inStaticCallClassName) {
                        break;
                    }
                    if (! empty($this->classStack)) {
                        return new Node\Name\FullyQualified(end($this->classStack), $node->getAttributes());
                    }

                    break;
                case 'parent':
                    if ($this->inStaticCallClassName) {
                        break;
                    }
                    if (! empty($this->parentStack) && '' !== end($this->parentStack)) {
                        return new Node\Name\FullyQualified(end($this->parentStack), $node->getAttributes());
                    }
            }
        } elseif ($node instanceof Node\Scalar\MagicConst\Class_) {
            // Inside a trait, __CLASS__ is the composing (using) class — same as self::class
            // (#26459, Zend/zend_compile.c). Do not bake the trait name as a string literal.
            // Lexical `self` is preserved above so TraitSelfClassScope binds at runtime.
            if (! empty($this->traitStack)) {
                return new Node\Expr\ClassConstFetch(
                    new Node\Name('self'),
                    new Node\Identifier('class'),
                    $node->getAttributes()
                );
            }
            if (! empty($this->classStack)) {
                return new Node\Scalar\String_(end($this->classStack), $node->getAttributes());
            }

            // Global scope — Zend resolves T_CLASS_C to '' (#11910, zend_compile.c).
            return new Node\Scalar\String_('', $node->getAttributes());
        } elseif ($node instanceof Node\Scalar\MagicConst\Trait_) {
            if (! empty($this->traitStack)) {
                return new Node\Scalar\String_(end($this->traitStack), $node->getAttributes());
            }

            return new Node\Scalar\String_('', $node->getAttributes());
        } elseif ($node instanceof Node\Scalar\MagicConst\Namespace_) {
            if (! empty($this->namespaceStack)) {
                return new Node\Scalar\String_(end($this->namespaceStack), $node->getAttributes());
            }
            if (! empty($this->classStack)) {
                return new Node\Scalar\String_($this->stripClass(end($this->classStack)), $node->getAttributes());
            }

            return new Node\Scalar\String_('', $node->getAttributes());
        } elseif ($node instanceof Node\Scalar\MagicConst\Function_) {
            if (! empty($this->methodStack)) {
                return new Node\Scalar\String_($this->shortFunctionName(end($this->methodStack)), $node->getAttributes());
            }
            if (! empty($this->functionStack)) {
                return new Node\Scalar\String_($this->shortFunctionName(end($this->functionStack)), $node->getAttributes());
            }

            // Class/trait const and other non-function contexts — Zend resolves to '' (#10125).
            return new Node\Scalar\String_('', $node->getAttributes());
        } elseif ($node instanceof Node\Scalar\MagicConst\Method) {
            if (! empty($this->methodStack)) {
                return new Node\Scalar\String_(end($this->methodStack), $node->getAttributes());
            }
            if (! empty($this->functionStack)) {
                return new Node\Scalar\String_(end($this->functionStack), $node->getAttributes());
            }

            return new Node\Scalar\String_('', $node->getAttributes());
        }
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            array_pop($this->namespaceStack);
        } elseif ($node instanceof Node\Stmt\ClassLike) {
            assert(end($this->classStack) === $node->namespacedName->toString());
            array_pop($this->classStack);
            if ($node instanceof Node\Stmt\Trait_) {
                array_pop($this->traitStack);
            }
            array_pop($this->parentStack);
        } elseif ($node instanceof Node\Stmt\Function_) {
            assert(end($this->functionStack) === $node->namespacedName->toString());
            array_pop($this->functionStack);
        } elseif ($node instanceof Node\Stmt\ClassMethod) {
            assert(end($this->methodStack) === end($this->classStack).'::'.$node->name);
            array_pop($this->methodStack);
            if (null !== $this->propertyNameFromHookMethod($node->name->name)) {
                array_pop($this->propertyStack);
            }
        } elseif ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            assert(end($this->functionStack) === '{closure}');
            assert(end($this->methodStack) === '{closure}');
            array_pop($this->functionStack);
            array_pop($this->methodStack);
        } elseif ($node instanceof Node\Expr\StaticCall) {
            $this->inStaticCallClassName = false;
        }
    }

    private function markTypeHintPreserveLexical(Node $type): void
    {
        if ($type instanceof Node\NullableType) {
            $this->markTypeHintPreserveLexical($type->type);

            return;
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $sub) {
                $this->markTypeHintPreserveLexical($sub);
            }

            return;
        }
        if ($type instanceof Node\Name || $type instanceof Node\Identifier) {
            $type->setAttribute(self::PRESERVE_LEXICAL_TYPE, true);
        }
    }

    private function propertyNameFromHookMethod(string $method): ?string
    {
        $lc = strtolower($method);
        foreach ([self::PROPERTY_GET_HOOK_PREFIX, self::PROPERTY_SET_HOOK_PREFIX] as $prefix) {
            if (str_starts_with($lc, $prefix)) {
                return substr($method, strlen($prefix));
            }
        }

        return null;
    }

    /**
     * Forward 8.4 profile gate — mirrors PHPCompiler\CompilerVersion::supportsPropertyHooks() without coupling namespaces.
     */
    private function propertyHooksProfileEnabled(): bool
    {
        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }
        $raw = trim($raw);
        if (preg_match('/^\d+\.\d+$/', $raw)) {
            $version = $raw.'.0';
        } elseif (preg_match('/^\d+\.\d+\.\d+/', $raw, $m)) {
            $version = $m[0];
        } else {
            return false;
        }

        return version_compare($version, '8.4.0', '>=');
    }

    private function stripClass($class)
    {
        $parts = explode('\\', $class);
        array_pop($parts);

        return implode('\\', $parts);
    }

    private function shortFunctionName(string $scoped): string
    {
        if (str_contains($scoped, '::')) {
            $parts = explode('::', $scoped);

            return end($parts);
        }
        $parts = explode('\\', $scoped);

        return end($parts);
    }

    private function anonymousClassName(Node\Stmt\ClassLike $node): string
    {
        $line = $node->getStartLine();
        if (null === $line) {
            $line = 0;
        }

        // php-src Zend/zend_compile.c — parent class if present, else first
        // implemented interface, else "class" (#28840).
        $prefix = 'class';
        if (!empty($node->extends) && !is_array($node->extends)) {
            $prefix = $node->extends->getLast();
        } elseif (!empty($node->implements) && \is_array($node->implements) && isset($node->implements[0])) {
            $prefix = $node->implements[0]->getLast();
        }

        $file = $this->compilationUnitFile;
        if ('' === $file) {
            $attrs = $node->getAttributes();
            if (isset($attrs['fileName']) && is_string($attrs['fileName'])) {
                $file = $attrs['fileName'];
            }
        }
        if ('' !== $file && is_file($file)) {
            $real = realpath($file);
            if (false !== $real) {
                $file = $real;
            }
        }

        $id = $this->anonymousClassCounter++;

        return $prefix.'@anonymous'."\0".$file.':'.$line.'$'.$id;
    }

    private function repairCommentsCallback($match)
    {
        $type = $match[2];
        $type = preg_replace('((?<=^|\|)((?i:self)|\$this)(?=\[|$|\|))', end($this->classStack), $type);
        return '@'.$match[1].' '.$type;
    }

    private function repairComments(Node $node)
    {
        $comment = $node->getDocComment();
        if ($comment && ! empty($this->classStack)) {
            $regex = '(@(param|return|var|type)\\s+(\\S+))i';

            $comment = new Doc(
                preg_replace_callback(
                    $regex,
                    [$this, 'repairCommentsCallback'],
                    $comment->getText()
                ),
                $comment->getLine(),
                $comment->getFilePos()
            );

            $node->setDocComment($comment);
        }
    }
}
