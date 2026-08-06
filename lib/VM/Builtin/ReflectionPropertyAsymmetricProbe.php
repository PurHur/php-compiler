<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\MethodVisibility;
use PHPCompiler\PropertyVisibility;
use PHPCompiler\VM\ReflectionSupport;
use PHPCfg\Func as CfgFunc;

/**
 * ReflectionProperty::{isPrivateSet,isProtectedSet,isPrivateGet,isProtectedGet,isPublicGet}
 * — VM (#6977, #28185, ext/reflection/php_reflection.c).
 *
 * php-src registers isPrivateSet/isProtectedSet only on the set side — no isPublicSet.
 */
final class ReflectionPropertyAsymmetricProbe extends VmClassMethod
{
    private const SIDE_SET = 'set';
    private const SIDE_GET = 'get';

    public static function isPrivateSet(): self
    {
        return new self('isPrivateSet', self::SIDE_SET, CfgFunc::FLAG_PRIVATE);
    }

    public static function isProtectedSet(): self
    {
        return new self('isProtectedSet', self::SIDE_SET, CfgFunc::FLAG_PROTECTED);
    }

    public static function isPrivateGet(): self
    {
        return new self('isPrivateGet', self::SIDE_GET, CfgFunc::FLAG_PRIVATE);
    }

    public static function isProtectedGet(): self
    {
        return new self('isProtectedGet', self::SIDE_GET, CfgFunc::FLAG_PROTECTED);
    }

    public static function isPublicGet(): self
    {
        return new self('isPublicGet', self::SIDE_GET, 0);
    }

    public function __construct(
        string $methodName,
        private readonly string $side,
        private readonly int $flag
    ) {
        parent::__construct($methodName);
    }

    public function execute(Frame $frame): void
    {
        $receiver = ReflectionSupport::requireReflectionProperty($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionProperty refers to unknown class in this compiler build');
        }
        $property = ReflectionSupport::propertyNameFromReflection($receiver);
        $meta = VmReflection::propertyVisibilityMeta($entry, $property, $ctx);
        if (null === $meta) {
            ReflectionSupport::throwReflectionException(
                ReflectionSupport::propertyNotFoundMessage($className, $property)
            );
        }

        $effective = self::SIDE_SET === $this->side
            ? PropertyVisibility::effectiveSetVisibility($meta['visibility'], $meta['setVisibility'])
            : PropertyVisibility::effectiveGetVisibility($meta['visibility'], $meta['getVisibility']);

        $result = 0 === $this->flag
            ? MethodVisibility::isPublic($effective)
            : ($effective & $this->flag) !== 0;

        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($result);
        }
    }
}
