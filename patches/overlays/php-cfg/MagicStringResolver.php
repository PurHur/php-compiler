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
        } elseif ($node instanceof Node\Stmt\ClassMethod) {
            $this->methodStack[] = end($this->classStack).'::'.$node->name;
            $prop = $this->propertyNameFromHookMethod($node->name->name);
            if (null !== $prop) {
                $this->propertyStack[] = $prop;
            }
        } elseif ($node instanceof Node\Expr\StaticCall) {
            $this->inStaticCallClassName = true;
        } elseif ($node instanceof Node\Expr\ConstFetch) {
            if ('__property__' === strtolower($node->name->toString())) {
                $name = $this->propertyStack !== [] ? end($this->propertyStack) : '';

                return new Node\Scalar\String_($name, $node->getAttributes());
            }
        } elseif ($node instanceof Node\Name) {
            switch (strtolower($node->toString())) {
                case 'self':
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
            if (! empty($this->classStack)) {
                return new Node\Scalar\String_(end($this->classStack), $node->getAttributes());
            }
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
        } elseif ($node instanceof Node\Expr\StaticCall) {
            $this->inStaticCallClassName = false;
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

        $prefix = 'class';
        if (!empty($node->extends) && !is_array($node->extends)) {
            $prefix = $node->extends->getLast();
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
