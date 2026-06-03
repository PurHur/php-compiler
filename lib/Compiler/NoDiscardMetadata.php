<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCompiler\Block;
use PhpParser\Node;

/**
 * #[\NoDiscard] attribute metadata (PHP 8.4+, Zend zend_attributes.c, #5078).
 */
final class NoDiscardMetadata
{
    public function __construct(
        public readonly ?string $message = null,
    ) {
    }

    public static function fromOp(Op $op): ?self
    {
        if (!$op->hasAttribute('attrGroups')) {
            return null;
        }
        $groups = $op->getAttribute('attrGroups');
        if (!\is_array($groups)) {
            return null;
        }

        return self::fromAttrGroups($groups);
    }

    /**
     * @param list<Node\AttributeGroup> $groups
     */
    public static function fromAttrGroups(array $groups): ?self
    {
        foreach ($groups as $group) {
            foreach ($group->attrs as $attr) {
                if (!self::isNoDiscardAttribute($attr)) {
                    continue;
                }

                return self::fromNoDiscardAttribute($attr);
            }
        }

        return null;
    }

    public static function applyToBlock(Block $block, Op $op): void
    {
        $meta = self::fromOp($op);
        if (null === $meta) {
            return;
        }
        $block->noDiscard = true;
        $block->noDiscardMessage = $meta->message;
    }

    public function formatFunction(string $name): string
    {
        return $this->formatCallee('function', $name.'()');
    }

    public function formatMethod(string $class, string $method): string
    {
        return $this->formatCallee('function', $class.'::'.$method.'()');
    }

    private function formatCallee(string $kind, string $callee): string
    {
        $base = 'The return value of '.$kind.' '.$callee
            .' should either be used or intentionally ignored by casting it as (void)';
        if (null !== $this->message && '' !== $this->message) {
            return $base.', '.$this->message;
        }

        return $base;
    }

    private static function isNoDiscardAttribute(Node\Attribute $attr): bool
    {
        $name = ltrim($attr->name->toString(), '\\');

        return 'NoDiscard' === $name || str_ends_with($name, '\\NoDiscard');
    }

    private static function fromNoDiscardAttribute(Node\Attribute $attr): self
    {
        $message = null;
        foreach ($attr->args as $arg) {
            if (null === $arg->name) {
                $message = self::scalarString($arg->value);
                break;
            }
            if ('message' === strtolower($arg->name->toString())) {
                $message = self::scalarString($arg->value);
            }
        }

        return new self($message);
    }

    private static function scalarString(?Node $node): ?string
    {
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }

        return null;
    }
}
