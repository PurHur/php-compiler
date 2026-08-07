<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\MethodVisibility;
use PHPCompiler\PropertyVisibility;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * ReflectionProperty::{isReadable,isWritable} — PHP 8.5 probes (ext/reflection/php_reflection.stub.php; #28533).
 *
 * Signature: (?string $scope, ?object $object = null): bool
 */
final class ReflectionPropertyAccessProbe extends VmClassMethod
{
    private const MODE_READABLE = 'readable';
    private const MODE_WRITABLE = 'writable';

    public static function isReadable(): self
    {
        return new self('isReadable', self::MODE_READABLE);
    }

    public static function isWritable(): self
    {
        return new self('isWritable', self::MODE_WRITABLE);
    }

    public function __construct(
        string $methodName,
        private readonly string $mode
    ) {
        parent::__construct($methodName);
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionProperty($frame, $frame->calledArgs[0]);
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 1) {
            throw new \ArgumentCountError(
                'ReflectionProperty::'.$this->getName().'() expects at least 1 argument, '.$argc.' given'
            );
        }
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionProperty refers to unknown class in this compiler build');
        }
        $property = ReflectionSupport::propertyNameFromReflection($receiver);
        if (VmReflection::isEnumReflectionPseudoProperty($entry, $property)) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(self::MODE_READABLE === $this->mode);
            }

            return;
        }
        $meta = VmReflection::findClassProperty($entry, $property, $ctx);
        if (null === $meta) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::propertyNotFoundMessage($className, $property)
            );
        }

        $scopeClassLc = self::resolveScopeClassLc($frame, $ctx, $frame->calledArgs[1]);
        $declaringLc = strtolower(ltrim($entry->name, '\\'));
        $object = self::optionalObjectArg($this->getName(), $frame->calledArgs[2] ?? null);

        $result = self::MODE_READABLE === $this->mode
            ? self::probeReadable($ctx, $meta, $declaringLc, $scopeClassLc, $object, $property)
            : self::probeWritable($ctx, $meta, $declaringLc, $scopeClassLc, $object, $property);

        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($result);
        }
    }

    private static function probeReadable(
        Context $ctx,
        \PHPCompiler\VM\ClassProperty $meta,
        string $declaringLc,
        ?string $scopeClassLc,
        ?ObjectEntry $object,
        string $property
    ): bool {
        $getVis = PropertyVisibility::effectiveGetVisibility($meta->visibility, $meta->getVisibility);
        if (!self::visibilityAccessibleFromScope($ctx, $getVis, $declaringLc, $scopeClassLc)) {
            return false;
        }
        if (null !== $object && $meta->propertyHookVirtual) {
            return null !== $meta->getHookMethodLc;
        }
        if (null !== $object && !$meta->propertyHookVirtual) {
            $slot = $object->getProperty($property);
            if ($slot->isUndefined()) {
                return false;
            }
        }

        return true;
    }

    private static function probeWritable(
        Context $ctx,
        \PHPCompiler\VM\ClassProperty $meta,
        string $declaringLc,
        ?string $scopeClassLc,
        ?ObjectEntry $object,
        string $property
    ): bool {
        if ($meta->readonly) {
            return false;
        }
        $readVis = PropertyVisibility::effectiveGetVisibility($meta->visibility, $meta->getVisibility);
        if (!self::visibilityAccessibleFromScope($ctx, $readVis, $declaringLc, $scopeClassLc)) {
            return false;
        }
        $setVis = PropertyVisibility::effectiveSetVisibility($meta->visibility, $meta->setVisibility);
        if (!self::visibilityAccessibleFromScope($ctx, $setVis, $declaringLc, $scopeClassLc)) {
            return false;
        }
        if (null !== $object && $meta->propertyHookVirtual) {
            return null !== $meta->setHookMethodLc;
        }

        return true;
    }

    private static function visibilityAccessibleFromScope(
        Context $ctx,
        int $visibility,
        string $declaringClassLc,
        ?string $scopeClassLc
    ): bool {
        if (MethodVisibility::isPublic($visibility)) {
            return true;
        }
        if (null === $scopeClassLc) {
            return false;
        }
        if ($scopeClassLc === $declaringClassLc) {
            return true;
        }
        if (($visibility & CfgFunc::FLAG_PRIVATE) !== 0) {
            return false;
        }
        if (($visibility & CfgFunc::FLAG_PROTECTED) !== 0) {
            return VmReflection::isSubclassOf($ctx, $scopeClassLc, $declaringClassLc);
        }

        return true;
    }

    private static function resolveScopeClassLc(Frame $frame, Context $ctx, Variable $scopeArg): ?string
    {
        $scopeArg = $scopeArg->resolveIndirect();
        if (Variable::TYPE_NULL === $scopeArg->type) {
            return null;
        }
        $className = VmReflection::stringArg($scopeArg, 'ReflectionProperty scope', 1);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \Error('Class "'.$className.'" not found');
        }

        return strtolower(ltrim($entry->name, '\\'));
    }

    private static function optionalObjectArg(string $method, ?Variable $objectArg): ?ObjectEntry
    {
        if (null === $objectArg) {
            return null;
        }
        $objectArg = $objectArg->resolveIndirect();
        if (Variable::TYPE_NULL === $objectArg->type) {
            return null;
        }
        if (Variable::TYPE_OBJECT !== $objectArg->type) {
            throw new \TypeError(
                'ReflectionProperty::'.$method.'() argument #2 ($object) must be of type ?object, '
                .ReflectionSupport::valueTypeLabelPublic($objectArg).' given'
            );
        }

        return $objectArg->toObject();
    }
}
