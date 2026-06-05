<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Param;
use PhpParser\NodeVisitorAbstract;
use PHPCompiler\Compiler\CompileFatal;

/**
 * Compile-time check: enums cannot declare instance properties (#6005).
 *
 * php-cfg drops Property nodes from enum bodies; validate on php-parser AST before CFG.
 * php-src: Zend/zend_compile.c — zend_compile_enum
 */
final class EnumPropertyCompileCheck extends NodeVisitorAbstract
{
    public static function messageFor(string $enumName): string
    {
        return 'Enum ' . $enumName . ' cannot include properties';
    }

    public function enterNode(Node $node)
    {
        if (!$node instanceof Enum_) {
            return null;
        }

        $enumName = $node->name->toString();
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Property) {
                $this->fatal($enumName, $stmt);
            }
            if ($stmt instanceof ClassMethod && $this->methodHasPromotedProperty($stmt)) {
                $this->fatal($enumName, $stmt);
            }
        }

        return null;
    }

    private function methodHasPromotedProperty(ClassMethod $method): bool
    {
        foreach ($method->params as $param) {
            if ($param instanceof Param && $this->isPromotedParam($param)) {
                return true;
            }
        }

        return false;
    }

    private function isPromotedParam(Param $param): bool
    {
        return 0 !== ($param->flags & (Class_::MODIFIER_PUBLIC | Class_::MODIFIER_PROTECTED | Class_::MODIFIER_PRIVATE));
    }

    private function fatal(string $enumName, Node $node): void
    {
        $file = $node->getAttribute('fileName', 'unknown');
        if (!is_string($file) || '' === $file) {
            $file = 'unknown';
        }

        throw new CompileFatal(
            $file,
            max(1, $node->getStartLine()),
            self::messageFor($enumName)
        );
    }
}
