<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PhpParser\Node;

/**
 * #[\Deprecated] attribute metadata (PHP 8.4, Zend zend_attributes.c parity, #3569).
 */
final class DeprecatedMetadata
{
    public function __construct(
        public readonly ?string $message = null,
        public readonly ?string $since = null,
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
                if (!self::isDeprecatedAttribute($attr)) {
                    continue;
                }

                return self::fromDeprecatedAttribute($attr);
            }
        }

        return null;
    }

    public function formatFunction(string $name): string
    {
        return 'Function '.$name.'() is deprecated'.$this->suffix();
    }

    public function formatMethod(string $class, string $method): string
    {
        return 'Method '.$class.'::'.$method.'() is deprecated'.$this->suffix();
    }

    public function formatConstant(string $class, string $constant): string
    {
        return 'Constant '.$class.'::'.$constant.' is deprecated'.$this->suffix();
    }

    private function suffix(): string
    {
        if (null !== $this->since && null !== $this->message) {
            return ' since '.$this->since.', '.$this->message;
        }
        if (null !== $this->since) {
            return ' since '.$this->since;
        }
        if (null !== $this->message) {
            return ', '.$this->message;
        }

        return '';
    }

    private static function isDeprecatedAttribute(Node\Attribute $attr): bool
    {
        $name = ltrim($attr->name->toString(), '\\');

        return 'Deprecated' === $name || str_ends_with($name, '\\Deprecated');
    }

    private static function fromDeprecatedAttribute(Node\Attribute $attr): self
    {
        $message = null;
        $since = null;
        $positional = 0;
        foreach ($attr->args as $arg) {
            if (null === $arg->name) {
                if (0 === $positional) {
                    $message = self::scalarString($arg->value);
                } elseif (1 === $positional) {
                    $since = self::scalarString($arg->value);
                }
                ++$positional;
                continue;
            }
            $param = strtolower($arg->name->toString());
            if ('message' === $param) {
                $message = self::scalarString($arg->value);
            } elseif ('since' === $param) {
                $since = self::scalarString($arg->value);
            }
        }

        return new self($message, $since);
    }

    private static function scalarString(?Node $node): ?string
    {
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }

        return null;
    }
}
