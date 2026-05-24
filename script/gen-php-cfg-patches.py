#!/usr/bin/env python3
"""Regenerate php-cfg vendor patches (issues #1230, #1233). Run after composer install."""
from __future__ import annotations

import subprocess
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PATCH_DIR = ROOT / "patches"

FCC_CLASS = """<?php

declare(strict_types=1);

namespace PHPCfg\\Op\\Expr;

use PHPCfg\\Op\\Expr;
use PHPCfg\\Operand;

/** PHP 8.1+ first-class callable: `foo(...)`, `Class::m(...)`, `$obj->m(...)` (#1230). */
class FirstClassCallable extends Expr
{
    public const KIND_FUNCTION = 1;
    public const KIND_STATIC = 2;
    public const KIND_METHOD = 3;

    public int $kind;

    /** Function name, static method name, or instance method name. */
    public Operand $name;

    /** Static call class (KIND_STATIC). */
    public ?Operand $class = null;

    /** Instance receiver (KIND_METHOD). */
    public ?Operand $var = null;

    public function __construct(
        int $kind,
        Operand $name,
        ?Operand $class = null,
        ?Operand $var = null,
        array $attributes = []
    ) {
        parent::__construct($attributes);
        $this->kind = $kind;
        $this->name = $this->addReadRef($name);
        if (null !== $class) {
            $this->class = $this->addReadRef($class);
        }
        if (null !== $var) {
            $this->var = $this->addReadRef($var);
        }
    }

    public function getVariableNames(): array
    {
        return ['name', 'class', 'var', 'result'];
    }

    public function getType(): string
    {
        return 'Expr_FirstClassCallable';
    }
}
"""


def patch_parser_fcc(orig: str) -> str:
    insert_is = """
    protected function isFirstClassCallable(array $args): bool
    {
        return 1 === count($args) && $args[0] instanceof Node\\VariadicPlaceholder;
    }

"""
    func_hook = """        if ($this->isFirstClassCallable($expr->args)) {
            return new Op\\Expr\\FirstClassCallable(
                Op\\Expr\\FirstClassCallable::KIND_FUNCTION,
                $this->readVariable($this->parseExprNode($expr->name)),
                null,
                null,
                $this->mapAttributes($expr)
            );
        }

"""
    method_hook = """        if ($this->isFirstClassCallable($expr->args)) {
            return new Op\\Expr\\FirstClassCallable(
                Op\\Expr\\FirstClassCallable::KIND_METHOD,
                $this->readVariable($this->parseExprNode($expr->name)),
                null,
                $this->readVariable($this->parseExprNode($expr->var)),
                $this->mapAttributes($expr)
            );
        }

"""
    static_hook = """        if ($this->isFirstClassCallable($expr->args)) {
            return new Op\\Expr\\FirstClassCallable(
                Op\\Expr\\FirstClassCallable::KIND_STATIC,
                $this->readVariable($this->parseExprNode($expr->name)),
                $this->readVariable($this->parseExprNode($expr->class)),
                null,
                $this->mapAttributes($expr)
            );
        }

"""
    text = orig
    text = text.replace(
        "    protected function parseExpr_FuncCall(Expr\\FuncCall $expr)\n    {\n",
        insert_is + "    protected function parseExpr_FuncCall(Expr\\FuncCall $expr)\n    {\n" + func_hook,
        1,
    )
    text = text.replace(
        "    protected function parseExpr_MethodCall(Expr\\MethodCall $expr)\n    {\n",
        "    protected function parseExpr_MethodCall(Expr\\MethodCall $expr)\n    {\n" + method_hook,
        1,
    )
    text = text.replace(
        "    protected function parseExpr_StaticCall(Expr\\StaticCall $expr)\n    {\n",
        "    protected function parseExpr_StaticCall(Expr\\StaticCall $expr)\n    {\n" + static_hook,
        1,
    )
    return text


def patch_magic(orig: str) -> str:
    text = orig.replace(
        "        if ($node instanceof Node\\Stmt\\ClassLike) {\n            $this->classStack[] = $node->namespacedName->toString();\n",
        """        if ($node instanceof Node\\Stmt\\ClassLike) {
            if (null === $node->namespacedName) {
                $node->namespacedName = new Node\\Name\\FullyQualified(
                    $this->anonymousClassName($node),
                    $node->getAttributes()
                );
            }
            $this->classStack[] = $node->namespacedName->toString();
""",
        1,
    )
    anon_helper = """
    private function anonymousClassName(Node\\Stmt\\ClassLike $node): string
    {
        $start = $node->getStartLine();
        if (null === $start) {
            $start = 0;
        }

        return 'AnonymousClass@' . $start;
    }

"""
    return text.replace(
        "    private function repairComments(Node $node)\n",
        anon_helper + "    private function repairComments(Node $node)\n",
        1,
    )


def patch_parser_anon(fcc_text: str) -> str:
    old = """    protected function parseExpr_New(Expr\\New_ $expr)
    {
        return new Op\\Expr\\New_(
            $this->readVariable($this->parseExprNode($expr->class)),
            $this->parseExprList($expr->args, self::MODE_READ),
            $this->mapAttributes($expr)
        );
    }
"""
    new = """    protected function parseExpr_New(Expr\\New_ $expr)
    {
        if ($expr->class instanceof Stmt\\Class_) {
            $this->parseStmt_Class($expr->class);
            $class = $this->readVariable($this->parseExprNode($expr->class->namespacedName));
        } else {
            $class = $this->readVariable($this->parseExprNode($expr->class));
        }

        return new Op\\Expr\\New_(
            $class,
            $this->parseExprList($expr->args, self::MODE_READ),
            $this->mapAttributes($expr)
        );
    }
"""
    return fcc_text.replace(old, new, 1)


def unified_diff(path: str, old: str, new: str) -> str:
    with tempfile.NamedTemporaryFile("w", delete=False, suffix=".old") as a, tempfile.NamedTemporaryFile(
        "w", delete=False, suffix=".new"
    ) as b:
        a.write(old)
        b.write(new)
        a.flush()
        b.flush()
        result = subprocess.run(["diff", "-u", a.name, b.name], capture_output=True, text=True)
    lines = result.stdout.splitlines()
    if not lines:
        return ""
    lines[0] = f"--- {path}"
    lines[1] = f"+++ {path}"
    return "\n".join(lines) + "\n"


def new_file_diff(path: str, content: str) -> str:
    with tempfile.NamedTemporaryFile("w", delete=False) as f:
        f.write(content)
        f.flush()
        result = subprocess.run(["diff", "-u", "/dev/null", f.name], capture_output=True, text=True)
    lines = result.stdout.splitlines()
    lines[0] = "--- /dev/null"
    lines[1] = f"+++ {path}"
    return "\n".join(lines) + "\n"


def main() -> None:
    parser_path = ROOT / "vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php"
    magic_path = ROOT / "vendor/ircmaxell/php-cfg/lib/PHPCfg/AstVisitor/MagicStringResolver.php"
    if not parser_path.is_file():
        raise SystemExit("Run composer install first")

    parser_orig = parser_path.read_text()
    magic_orig = magic_path.read_text()
    parser_fcc = patch_parser_fcc(parser_orig)
    parser_anon = patch_parser_anon(parser_fcc)
    magic_new = patch_magic(magic_orig)

    fcc_patch = new_file_diff(
        "vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/FirstClassCallable.php",
        FCC_CLASS,
    ) + unified_diff("vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php", parser_orig, parser_fcc)

    anon_patch = unified_diff(
        "vendor/ircmaxell/php-cfg/lib/PHPCfg/AstVisitor/MagicStringResolver.php",
        magic_orig,
        magic_new,
    ) + unified_diff("vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php", parser_fcc, parser_anon)

    (PATCH_DIR / "php-cfg-first-class-callable.patch").write_text(fcc_patch)
    (PATCH_DIR / "php-cfg-anonymous-class.patch").write_text(anon_patch)
    print("Wrote", PATCH_DIR / "php-cfg-first-class-callable.patch")
    print("Wrote", PATCH_DIR / "php-cfg-anonymous-class.patch")


if __name__ == "__main__":
    main()
