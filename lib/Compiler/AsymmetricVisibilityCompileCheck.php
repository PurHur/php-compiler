<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Func as CfgFunc;
use PHPCfg\Op;
use PHPCfg\Op\Expr\Param;
use PHPCfg\Operand;
use PHPCfg\Script;
use PHPCompiler\Ast\AsymmetricVisibilityRewriter;
use PHPCompiler\MethodVisibility;

/**
 * Compile-time validation for PHP 8.4 asymmetric property visibility (#6589).
 *
 * php-src: Zend/zend_API.c — zend_declare_typed_property();
 * Zend/zend_compile.c — zend_add_member_modifier() (duplicate PPP / PPP_SET).
 */
final class AsymmetricVisibilityCompileCheck
{
    public const MULTIPLE_MODIFIERS_MESSAGE = 'Multiple access type modifiers are not allowed';

    public const WEAKER_THAN_SET_MESSAGE = 'Visibility of property %s::$%s must not be weaker than set visibility';

    public const ASYMMETRIC_REQUIRES_TYPE_MESSAGE = 'Property with asymmetric visibility %s::$%s must have type';

    public static function validate(Script $script): void
    {
        $check = new self();
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof Op\Stmt\Class_ || $child instanceof Op\Stmt\Interface_) {
                $check->walkClassLike($child);
            }
        }
    }

    private function walkClassLike(Op\Stmt\Class_|Op\Stmt\Interface_ $class): void
    {
        $classDisplay = $this->operandDisplayName($class->name, 'class');

        foreach ($class->stmts->children as $member) {
            if ($member instanceof Op\Stmt\Property) {
                $this->verifyProperty(
                    $classDisplay,
                    $this->propertyDisplayName($member->name),
                    MethodVisibility::mask($member->visibility),
                    $this->setVisibilityFromProperty($member),
                    $member->declaredType
                );
                continue;
            }
            if (!$member instanceof Op\Stmt\ClassMethod || !$this->isConstructor($member)) {
                continue;
            }
            foreach ($member->func->params as $param) {
                if (!$this->isPromotedParam($param)) {
                    continue;
                }
                $this->verifyPromotedParam(
                    $classDisplay,
                    $this->propertyDisplayName($param->name),
                    $param
                );
            }
        }
    }

    /**
     * Promoted asymmetric visibility — same rules as declared properties (#6981, RFC asymmetric-visibility-v2).
     *
     * php-src: Zend/zend_compile.c — zend_compile_property_info() on promoted params; set must not
     * be more permissive than read (RFC asymmetric-visibility-v2, #7308).
     */
    private function verifyPromotedParam(string $classDisplay, string $propertyName, Param $param): void
    {
        $readVisibility = MethodVisibility::mask($param->promotionFlags);
        $setVisibility = $this->setVisibilityFromParam($param);

        $this->verifyProperty(
            $classDisplay,
            $propertyName,
            $readVisibility,
            $setVisibility,
            $param->declaredType
        );
    }

    private function verifyProperty(
        string $classDisplay,
        string $propertyName,
        int $readVisibility,
        int $setVisibility,
        ?Op\Type $declaredType
    ): void {
        if (0 === $setVisibility) {
            return;
        }
        if (!$this->propertyHasDeclaredType($declaredType)) {
            throw new \CompileError(sprintf(self::ASYMMETRIC_REQUIRES_TYPE_MESSAGE, $classDisplay, $propertyName));
        }
        // php-src: set visibility must not be weaker than read visibility.
        // (E.g. `private public(set)` is invalid because writes would be less restricted than reads.)
        if (self::visibilityRank($readVisibility) > self::visibilityRank($setVisibility)) {
            throw new \CompileError(sprintf(self::WEAKER_THAN_SET_MESSAGE, $classDisplay, $propertyName));
        }
    }

    private function propertyHasDeclaredType(?Op\Type $declaredType): bool
    {
        return null !== $declaredType && !$declaredType instanceof Op\Type\Mixed_;
    }

    private function setVisibilityFromProperty(Op\Stmt\Property $property): int
    {
        if (property_exists($property, 'setVisibility') && 0 !== (int) $property->setVisibility) {
            return MethodVisibility::mask((int) $property->setVisibility);
        }

        return AsymmetricVisibilityRewriter::extractSetVisibilityFromAttributes($property->getAttributes());
    }

    private function setVisibilityFromParam(Param $param): int
    {
        if (property_exists($param, 'promotionSetVisibility') && 0 !== (int) $param->promotionSetVisibility) {
            return MethodVisibility::mask((int) $param->promotionSetVisibility);
        }

        return AsymmetricVisibilityRewriter::extractSetVisibilityFromAttributes($param->getAttributes());
    }

    private function getVisibilityFromParam(Param $param): int
    {
        if (property_exists($param, 'promotionGetVisibility') && 0 !== (int) $param->promotionGetVisibility) {
            return MethodVisibility::mask((int) $param->promotionGetVisibility);
        }

        return AsymmetricVisibilityRewriter::extractGetVisibilityFromAttributes($param->getAttributes());
    }

    private function isPromotedParam(Param $param): bool
    {
        return 0 !== MethodVisibility::mask($param->promotionFlags)
            || (property_exists($param, 'promotionSetVisibility') && 0 !== (int) $param->promotionSetVisibility)
            || 0 !== AsymmetricVisibilityRewriter::extractSetVisibilityFromAttributes($param->getAttributes());
    }

    private function isConstructor(Op\Stmt\ClassMethod $method): bool
    {
        $name = $method->func->name ?? null;
        if (!is_string($name)) {
            return false;
        }

        return '__construct' === strtolower($name);
    }

    /** php-src: zend_visibility_to_set_visibility() ordering on PPP / PPP_SET bits. */
    private static function visibilityRank(int $visibilityFlags): int
    {
        if (($visibilityFlags & CfgFunc::FLAG_PRIVATE) !== 0) {
            return 3;
        }
        if (($visibilityFlags & CfgFunc::FLAG_PROTECTED) !== 0) {
            return 2;
        }

        return 1;
    }

    private function propertyDisplayName(Operand $op): string
    {
        if ($op instanceof Operand\Literal && is_string($op->value)) {
            return $op->value;
        }
        if ($op instanceof Operand\Variable) {
            return $this->propertyDisplayName($op->name);
        }

        return 'property';
    }

    private function operandDisplayName(Operand $op, string $fallback): string
    {
        $name = $this->staticNameFromOperand($op);
        if (null === $name) {
            return $fallback;
        }
        if (str_contains($name, '\\')) {
            $parts = explode('\\', ltrim($name, '\\'));

            return end($parts) ?: $fallback;
        }

        return $name;
    }

    private function staticNameFromOperand(Operand $op): ?string
    {
        if ($op instanceof Operand\Literal && is_string($op->value)) {
            return $op->value;
        }
        if ($op instanceof Operand\Variable) {
            return $this->staticNameFromOperand($op->name);
        }

        return null;
    }
}
