<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Op\Expr\Param;
use PHPCfg\Script;

/**
 * Compile-time rejection of variadic constructor property promotion.
 *
 * php-src: Zend/zend_compile.c — promoted parameters cannot be variadic
 * (`Cannot declare variadic promoted property`) (#26515).
 */
final class VariadicPromotedPropertyCompileCheck
{
    public const MESSAGE = 'Cannot declare variadic promoted property';

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
        foreach ($class->stmts->children as $member) {
            if (!$member instanceof Op\Stmt\ClassMethod) {
                continue;
            }
            $name = $member->func->name ?? null;
            if (!is_string($name) || '__construct' !== strtolower($name)) {
                continue;
            }
            foreach ($member->func->params as $param) {
                if (!$this->isPromotedParam($param)) {
                    continue;
                }
                if (!$param->variadic) {
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

    private function isPromotedParam(Param $param): bool
    {
        return property_exists($param, 'promotionFlags') && 0 !== $param->promotionFlags;
    }
}
