<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Func as CfgFunc;
use PHPCfg\Op;
use PHPCfg\Op\Expr\Param;
use PHPCfg\Script;

/**
 * Compile-time rejection of constructor property promotion on abstract constructors.
 *
 * php-src: Zend/zend_compile.c — promoted parameters require a concrete constructor body
 * (`Cannot declare promoted property in an abstract constructor`) (#26529).
 *
 * Covers abstract class methods, interface methods (always abstract), and abstract trait methods.
 */
final class AbstractPromotedPropertyCompileCheck
{
    public const MESSAGE = 'Cannot declare promoted property in an abstract constructor';

    public static function validate(Script $script): void
    {
        $check = new self();
        foreach ($script->main->cfg->children as $child) {
            if ($child instanceof Op\Stmt\ClassLike) {
                $check->validateClassLike($child);
            }
        }
    }

    private function validateClassLike(Op\Stmt\ClassLike $class): void
    {
        $interfaceContext = $class instanceof Op\Stmt\Interface_;
        foreach ($class->stmts->children as $member) {
            if (!$member instanceof Op\Stmt\ClassMethod) {
                continue;
            }
            $name = $member->func->name ?? null;
            if (!is_string($name) || '__construct' !== strtolower($name)) {
                continue;
            }
            if (!$this->isAbstractConstructor($member, $interfaceContext)) {
                continue;
            }
            foreach ($member->func->params as $param) {
                if (!$this->isPromotedParam($param)) {
                    continue;
                }
                throw new CompileFatal(
                    $member->getFile(),
                    max(1, $member->getLine()),
                    self::MESSAGE
                );
            }
        }
    }

    private function isAbstractConstructor(Op\Stmt\ClassMethod $member, bool $interfaceContext): bool
    {
        // Interface methods are always abstract; PHPCfg may omit FLAG_ABSTRACT.
        if ($interfaceContext) {
            return true;
        }

        return 0 !== ($member->func->flags & CfgFunc::FLAG_ABSTRACT);
    }

    private function isPromotedParam(Param $param): bool
    {
        return property_exists($param, 'promotionFlags') && 0 !== $param->promotionFlags;
    }
}
