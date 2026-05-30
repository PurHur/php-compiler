<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCompiler\VM\Variable;
use PhpParser\Node;

/**
 * Compile-time PHP 8 attribute metadata (#1936, #3800).
 */
final class AttributeMetadata
{
    /**
     * @param list<Variable> $args Positional constructor arguments (compile-time only).
     */
    public function __construct(
        public readonly string $name,
        public readonly array $args = [],
    ) {
    }

    /**
     * @return list<self>
     */
    public static function listFromOp(Op $op): array
    {
        if (!$op->hasAttribute('attrGroups')) {
            return [];
        }
        $groups = $op->getAttribute('attrGroups');
        if (!\is_array($groups)) {
            return [];
        }

        return self::listFromAttrGroups($groups);
    }

    /**
     * @param list<Node\AttributeGroup> $groups
     *
     * @return list<self>
     */
    public static function listFromAttrGroups(array $groups): array
    {
        $out = [];
        foreach ($groups as $group) {
            foreach ($group->attrs as $attr) {
                $out[] = new self($attr->name->toString(), self::argsFromAttribute($attr));
            }
        }

        return $out;
    }

    /**
     * @return list<Variable>
     */
    private static function argsFromAttribute(Node\Attribute $attr): array
    {
        $args = [];
        foreach ($attr->args as $arg) {
            if (null !== $arg->name) {
                continue;
            }
            $vm = self::compileTimeValue($arg->value);
            if (null !== $vm) {
                $args[] = $vm;
            }
        }

        return $args;
    }

    private static function compileTimeValue(?Node $node): ?Variable
    {
        if ($node instanceof Node\Scalar\String_) {
            $v = new Variable(Variable::TYPE_STRING);
            $v->string($node->value);

            return $v;
        }
        if ($node instanceof Node\Scalar\LNumber) {
            $v = new Variable(Variable::TYPE_INTEGER);
            $v->int((int) $node->value);

            return $v;
        }
        if ($node instanceof Node\Scalar\DNumber) {
            $v = new Variable(Variable::TYPE_DOUBLE);
            $v->float((float) $node->value);

            return $v;
        }
        if ($node instanceof Node\Expr\ConstFetch && $node->name instanceof Node\Name) {
            $name = strtolower($node->name->toString());
            if ('null' === $name) {
                $v = new Variable(Variable::TYPE_NULL);
                $v->null();

                return $v;
            }
            if ('true' === $name) {
                $v = new Variable(Variable::TYPE_BOOL);
                $v->bool(true);

                return $v;
            }
            if ('false' === $name) {
                $v = new Variable(Variable::TYPE_BOOL);
                $v->bool(false);

                return $v;
            }
        }

        return null;
    }
}
