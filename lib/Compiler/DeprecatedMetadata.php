<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCompiler\CompilerVersion;
use PhpParser\Comment;
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
        if ($op->hasAttribute('phpcGlobalDeprecatedMetadata')) {
            $meta = $op->getAttribute('phpcGlobalDeprecatedMetadata');
            if ($meta instanceof self) {
                return $meta;
            }
        }
        if ($op->hasAttribute('attrGroups')) {
            $groups = $op->getAttribute('attrGroups');
            if (\is_array($groups)) {
                $fromAttr = self::fromAttrGroups($groups);
                if (null !== $fromAttr) {
                    return $fromAttr;
                }
            }
        }

        return self::fromOpDocComment($op);
    }

    /**
     * Attach #[\Deprecated] to a function/method body block for JIT/AOT call-site emission (#27331).
     *
     * Mirrors {@see NoDiscardMetadata::applyToBlock}.
     */
    public static function applyToBlock(\PHPCompiler\Block $block, Op $op): void
    {
        $meta = self::fromOp($op);
        if (null === $meta) {
            return;
        }
        $block->deprecated = $meta;
    }

    /**
     * Recover @deprecated docblock on class constants (zend_compile.c, ext/reflection/php_reflection.c, #17647).
     */
    public static function fromOpDocComment(Op $op): ?self
    {
        foreach (self::commentTextChunksFromOp($op) as $chunk) {
            $meta = self::fromDocCommentText($chunk);
            if (null !== $meta) {
                return $meta;
            }
        }

        return null;
    }

    public static function fromDocCommentText(string $text): ?self
    {
        if (!preg_match('/@deprecated\b/i', $text)) {
            return null;
        }
        if (preg_match('/@deprecated\s+(\S+)(?:\s+(.*))?\s*(?:\*\/|\*|$)/is', $text, $m)) {
            $first = trim($m[1]);
            $rest = isset($m[2]) ? trim($m[2]) : '';
            $rest = rtrim($rest, "*/ \t\n\r");
            if (preg_match('/^\d/', $first)) {
                return new self('' !== $rest ? $rest : null, $first);
            }

            return new self(trim($first.('' !== $rest ? ' '.$rest : '')), null);
        }

        return new self(null, null);
    }

    /**
     * @return list<string>
     */
    private static function commentTextChunksFromOp(Op $op): array
    {
        $chunks = [];
        foreach (['comments', 'docComment', 'doccomment'] as $key) {
            if (!$op->hasAttribute($key)) {
                continue;
            }
            $value = $op->getAttribute($key);
            if ('comments' === $key && \is_array($value)) {
                foreach ($value as $comment) {
                    $text = self::commentObjectText($comment);
                    if (null !== $text) {
                        $chunks[] = $text;
                    }
                }
                continue;
            }
            $text = self::commentObjectText($value);
            if (null !== $text) {
                $chunks[] = $text;
            }
        }

        return $chunks;
    }

    private static function commentObjectText(mixed $comment): ?string
    {
        if ($comment instanceof Comment) {
            return $comment->getText();
        }
        if (\is_object($comment) && method_exists($comment, 'getText')) {
            return $comment->getText();
        }
        if (\is_string($comment)) {
            return $comment;
        }

        return null;
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

    public function formatGlobalConstant(string $constant): string
    {
        return 'Constant '.$constant.' is deprecated'.$this->suffix();
    }

    public function formatClass(string $name): string
    {
        return 'Class '.$name.' is deprecated'.$this->suffix();
    }

    /**
     * Trait use-site notice (PHP 8.5+, Zend zend_execute / rfc:deprecated_traits, #22989).
     *
     * Bare `#[\Deprecated]` (no message/since) still emits — same as function/method/const use sites
     * (rfc:deprecated_attribute, #27825).
     */
    public function formatTraitUse(string $trait, string $class): string
    {
        return 'Trait '.$trait.' used by '.$class.' is deprecated'.$this->suffix();
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
     * Property-hook accessor notice (PHP 8.4+, Zend zend_attributes.c / zend_property_hooks.c, #26370).
     *
     * Zend formats hook methods as `Method Class::$prop::get()` / `::set()`, not the
     * synthetic `__phpc_property_*` names. Bare `#[\Deprecated]` still emits.
     */
    public function formatPropertyHook(string $class, string $property, string $hook): string
    {
        $hook = strtolower($hook);
        if ('set' !== $hook && 'get' !== $hook) {
            $hook = 'get';
        }

        return 'Method '.$class.'::$'.$property.'::'.$hook.'() is deprecated'.$this->suffix();
    }

    /**
     * Whether call/const/class use sites emit E_USER_DEPRECATED.
     *
     * Attribute presence alone is enough — bare `#[\Deprecated]` (no message/since) still
     * emits `Function f() is deprecated` / equivalent (php-src zend_execute.c +
     * rfc:deprecated_attribute, #27825). Message/since only shape the notice suffix.
     * Callers also gate on {@see CompilerVersion::supportsDeprecatedAttributeRuntimeNotices()}.
     */
    public function emitsRuntimeNotice(): bool
    {
        return true;
    }

    /**
     * Whether #[\Deprecated] is active for Reflection*::isDeprecated() (ext/reflection/php_reflection.c, #9760, #16821, #16867).
     *
     * php-src compares `since` against the effective language profile version so
     * `PHP_COMPILER_PROFILE=8.4` matches Zend 8.4 reflection while the 8.4.0-dev
     * reference profile (no forward gate) stays below `since: '8.4'`.
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
