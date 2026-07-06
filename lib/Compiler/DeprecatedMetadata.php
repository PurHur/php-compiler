<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCompiler\CompilerVersion;
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

    /**
     * @param list<array{name: ?string, value: mixed}> $args
     */
    public static function fromAttributeArgs(array $args): self
    {
        $message = null;
        $since = null;
        $positional = 0;
        foreach ($args as $arg) {
            $name = $arg['name'] ?? null;
            $value = $arg['value'];
            $str = \is_string($value) || \is_int($value) || \is_float($value) ? (string) $value : null;
            if (null === $name) {
                if (0 === $positional) {
                    $message = $str;
                } elseif (1 === $positional) {
                    $since = $str;
                }
                ++$positional;
                continue;
            }
            $param = strtolower((string) $name);
            if ('message' === $param) {
                $message = $str;
            } elseif ('since' === $param) {
                $since = $str;
            }
        }

        return new self($message, $since);
    }

    public static function fromAttributeEntry(AttributeEntry $entry): ?self
    {
        if (!self::isDeprecatedAttributeName($entry->name)) {
            return null;
        }

        return self::fromAttributeArgs($entry->args);
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

    public function formatClass(string $name): string
    {
        return 'Class '.$name.' is deprecated'.$this->suffix();
    }

    public function formatEnum(string $name): string
    {
        return 'Enum '.$name.' is deprecated'.$this->suffix();
    }

    public function formatEnumCase(string $class, string $case): string
    {
        return 'Enum case '.$class.'::'.$case.' is deprecated'.$this->suffix();
    }

    public function formatProperty(string $class, string $property): string
    {
        return 'Property '.$class.'::$'.$property.' is deprecated'.$this->suffix();
    }

    /**
     * Bare #[\Deprecated] (no message/since) is reflection metadata only — no E_USER_DEPRECATED (#4392, Zend zend_attributes.c).
     */
    public function emitsRuntimeNotice(): bool
    {
        return null !== $this->message || null !== $this->since;
    }

    /**
     * Whether #[\Deprecated] is active for Reflection*::isDeprecated() (ext/reflection/php_reflection.c, #9760, #16800).
     *
     * php-src sets ZEND_ACC_DEPRECATED at compile time only on PHP 8.4+; on 8.2 the flag is absent so isDeprecated()
     * is false even when since: '8.4' is present. Match that by gating since against
     * {@see CompilerVersion::languageProfileVersion()} (not reportedPhpVersion — 8.4.0-dev is < 8.4.0 for version_compare).
     */
    public function isDeprecatedForReflection(): bool
    {
        if (null !== $this->since) {
            return version_compare(
                CompilerVersion::languageProfileVersion(),
                self::normalizeSinceVersion($this->since),
                '>='
            );
        }

        return true;
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
        return self::isDeprecatedAttributeName($attr->name->toString());
    }

    private static function isDeprecatedAttributeName(string $name): bool
    {
        $name = ltrim($name, '\\');

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
        if ($node instanceof Node\Scalar\LNumber) {
            return (string) $node->value;
        }
        if ($node instanceof Node\Scalar\DNumber) {
            return (string) $node->value;
        }

        return null;
    }

    private static function normalizeSinceVersion(string $since): string
    {
        if (preg_match('/^\d+\.\d+$/', $since)) {
            return $since.'.0';
        }
        if (preg_match('/^\d+\.\d+\.\d+/', $since, $m)) {
            return $m[0];
        }

        return $since;
    }
}
