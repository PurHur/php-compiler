<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * ReflectionReference::fromArrayElement / getId (php-src ext/reflection/php_reflection.c; #22065).
 */
final class ReflectionReferenceSupport
{
    /** True when the array bucket zval is IS_REFERENCE (stored as TYPE_INDIRECT). */
    public static function bucketValueIsReference(Variable $bucketValue): bool
    {
        return Variable::TYPE_INDIRECT === $bucketValue->type;
    }

    /**
     * php-src is_ignorable_reference() — rc=1 self-referential array slots are not references.
     */
    public static function isIgnorableArrayReference(HashTable $ht, Variable $bucketValue): bool
    {
        if (!self::bucketValueIsReference($bucketValue)) {
            return false;
        }
        $target = $bucketValue->directIndirectTarget();
        if (null === $target || Variable::TYPE_ARRAY !== $target->type) {
            return false;
        }
        if ($target->toArray() !== $ht) {
            return false;
        }

        return true;
    }

    /** 20-byte opaque id stable for the lifetime of the reference cell (Zend getId shape). */
    public static function idForBucketValue(Variable $bucketValue): string
    {
        if (!self::bucketValueIsReference($bucketValue)) {
            throw new \LogicException('ReflectionReference id requested for non-reference bucket');
        }

        return substr(hash('sha1', 'phpc-ref:'.\spl_object_id($bucketValue), true), 0, 20);
    }
}
