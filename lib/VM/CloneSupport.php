<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Zend/zend_clones.c — clone operand must be object at runtime (#19097).
 *
 * Lazy ghosts/proxies: Zend/zend_lazy_objects.c zend_lazy_object_clone initializes
 * the source before clone (#29171); VM calls LazyObjectSupport::ensureInitialized
 * from TYPE_CLONE before cloneShallow.
 */
final class CloneSupport
{
    public const NON_OBJECT_ERROR_MESSAGE = '__clone method called on non-object';

    /**
     * Class name when clone_obj is disabled on $object or an ancestor
     * (Exception/Error #25870, WeakReference #25962).
     */
    public static function uncloneableDeniedClass(ObjectEntry $object, Context $context): ?string
    {
        $class = $object->class;
        while (null !== $class) {
            if ($class->denyClone) {
                return $object->class->name;
            }
            $parentLc = $class->parentLc;
            if (null === $parentLc || !isset($context->classes[$parentLc])) {
                return null;
            }
            $class = $context->classes[$parentLc];
        }

        return null;
    }

    public static function uncloneableObjectErrorMessage(string $className): string
    {
        return 'Trying to clone an uncloneable object of class '.$className;
    }
}
