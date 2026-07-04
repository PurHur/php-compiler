<?php

declare(strict_types=1);

namespace PHPCompiler\Ast;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Case_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\NodeVisitorAbstract;

/**
 * Resolve bare enum case names in match/switch arms when scrutinee is enum-typed (#6947).
 *
 * php-parser lowers `Pending => 1` to ConstFetch; Zend resolves against the match/switch
 * subject enum (zend_compile.c). Rewrite to ClassConstFetch before php-cfg lowering.
 */
final class EnumCaseMatchSwitchRewriter extends NodeVisitorAbstract
{
    /** @var array<string, array<string, true>> lowercased enum FQCN -> canonical case name -> true */
    private array $enumCases = [];

    private ?Name $namespace = null;

    /** @var null|string enum FQCN while traversing enum body */
    private ?string $currentEnumFqcn = null;

    /** @var list<array{params: array<string, string>, vars: array<string, string>}> */
    private array $scopeStack = [];

    /** @var array<string, string> file-scope variable (lowercase) => enum FQCN */
    private array $fileVars = [];

    public function beforeTraverse(array $nodes)
    {
        $this->collectEnums($nodes, null);

        return null;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Namespace_) {
            $this->namespace = $node->name;
        } elseif ($node instanceof Enum_) {
            $this->currentEnumFqcn = $this->enumFqcnFromEnumNode($node);
        } elseif ($node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_) {
            $params = [];
            foreach ($node->params as $param) {
                if (!$param instanceof Param) {
                    continue;
                }
                $enumFqcn = $this->enumFqcnFromType($param->type);
                if (null === $enumFqcn || !$param->var instanceof Expr\Variable || !is_string($param->var->name)) {
                    continue;
                }
                $params[strtolower($param->var->name)] = $enumFqcn;
            }
            $this->scopeStack[] = ['params' => $params, 'vars' => []];
        } elseif ($node instanceof Expression && $node->expr instanceof Expr\Assign) {
            $this->trackEnumAssignment($node->expr);
        } elseif ($node instanceof Expr\Match_) {
            $enumFqcn = $this->enumFqcnFromScrutinee($node->cond);
            if (null !== $enumFqcn) {
                foreach ($node->arms as $arm) {
                    if (!$arm instanceof MatchArm || null === $arm->conds) {
                        continue;
                    }
                    foreach ($arm->conds as $idx => $cond) {
                        $rewritten = $this->rewritePatternExpr($cond, $enumFqcn);
                        if (null !== $rewritten) {
                            $arm->conds[$idx] = $rewritten;
                        }
                    }
                }
            }
        } elseif ($node instanceof Switch_) {
            $enumFqcn = $this->enumFqcnFromScrutinee($node->cond);
            if (null !== $enumFqcn) {
                foreach ($node->cases as $case) {
                    if (!$case instanceof Case_ || null === $case->cond) {
                        continue;
                    }
                    $rewritten = $this->rewritePatternExpr($case->cond, $enumFqcn);
                    if (null !== $rewritten) {
                        $case->cond = $rewritten;
                    }
                }
            }
        } elseif ($node instanceof Expr\ConstFetch && null !== $this->currentEnumFqcn) {
            $rewritten = $this->rewritePatternExpr($node, $this->currentEnumFqcn);
            if (null !== $rewritten) {
                return $rewritten;
            }
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Enum_) {
            $this->currentEnumFqcn = null;
        } elseif ($node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_) {
            array_pop($this->scopeStack);
        } elseif ($node instanceof Namespace_) {
            $this->namespace = null;
        }

        return null;
    }

    /**
     * @param array<int, Node> $nodes
     */
    private function collectEnums(array $nodes, ?Name $namespace): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Namespace_) {
                $this->collectEnums($node->stmts ?? [], $node->name);
                continue;
            }
            if (!$node instanceof Enum_ || null === $node->name) {
                continue;
            }
            $fqcn = $this->enumFqcnFromShortName($node->name->toString(), $namespace);
            $cases = [];
            foreach ($node->stmts ?? [] as $stmt) {
                if ($stmt instanceof EnumCase) {
                    $cases[$stmt->name->toString()] = true;
                }
            }
            $this->enumCases[strtolower($fqcn)] = $cases;
        }
    }

    private function trackEnumAssignment(Expr\Assign $assign): void
    {
        if (!$assign->var instanceof Expr\Variable || !is_string($assign->var->name)) {
            return;
        }
        $enumFqcn = $this->enumFqcnFromExpr($assign->expr);
        if (null === $enumFqcn) {
            return;
        }
        $key = strtolower($assign->var->name);
        if ([] !== $this->scopeStack) {
            $idx = \count($this->scopeStack) - 1;
            $this->scopeStack[$idx]['vars'][$key] = $enumFqcn;

            return;
        }
        $this->fileVars[$key] = $enumFqcn;
    }

    private function enumFqcnFromScrutinee(Expr $expr): ?string
    {
        if ($expr instanceof Expr\Variable && is_string($expr->name)) {
            if ('this' === strtolower($expr->name) && null !== $this->currentEnumFqcn) {
                return $this->currentEnumFqcn;
            }
            $key = strtolower($expr->name);
            if ([] !== $this->scopeStack) {
                $scope = $this->scopeStack[\count($this->scopeStack) - 1];
                if (isset($scope['params'][$key])) {
                    return $scope['params'][$key];
                }
                if (isset($scope['vars'][$key])) {
                    return $scope['vars'][$key];
                }
            }
            if (isset($this->fileVars[$key])) {
                return $this->fileVars[$key];
            }
        }

        // Enum case constant scrutinee (E::A) does not import bare case names into arms (#15875).
        return null;
    }

    private function enumFqcnFromExpr(Expr $expr): ?string
    {
        if ($expr instanceof Expr\ClassConstFetch) {
            return $this->enumFqcnFromClassOperand($expr->class);
        }
        if ($expr instanceof Expr\StaticCall || $expr instanceof Expr\StaticPropertyFetch) {
            return $this->enumFqcnFromClassOperand($expr->class);
        }

        return null;
    }

    private function enumFqcnFromClassOperand($classOperand): ?string
    {
        if (!$classOperand instanceof Name) {
            return null;
        }
        $fqcn = $this->resolveTypeName($classOperand);
        if (isset($this->enumCases[strtolower($fqcn)])) {
            return $fqcn;
        }

        return null;
    }

    private function enumFqcnFromType(?Node $type): ?string
    {
        if (!$type instanceof Name) {
            return null;
        }
        $fqcn = $this->resolveTypeName($type);
        if (isset($this->enumCases[strtolower($fqcn)])) {
            return $fqcn;
        }

        return null;
    }

    private function enumFqcnFromEnumNode(Enum_ $enum): ?string
    {
        if (null === $enum->name) {
            return null;
        }

        return $this->enumFqcnFromShortName($enum->name->toString(), $this->namespace);
    }

    private function enumFqcnFromShortName(string $shortName, ?Name $namespace): string
    {
        if (null !== $namespace) {
            return (string) Name::concat($namespace, $shortName);
        }

        return $shortName;
    }

    private function resolveTypeName(Name $name): string
    {
        if ($name->isFullyQualified()) {
            return ltrim($name->toString(), '\\');
        }
        if (null !== $this->namespace) {
            return (string) Name::concat($this->namespace, $name);
        }

        return $name->toString();
    }

    private function rewritePatternExpr(Node $expr, string $enumFqcn): ?Expr\ClassConstFetch
    {
        if (!$expr instanceof Expr\ConstFetch || !$expr->name instanceof Name) {
            return null;
        }
        if ($expr->name->isUnqualified()) {
            $caseName = $expr->name->toString();
        } elseif ($expr->name->isFullyQualified() && !$expr->name->isQualified()) {
            // NameResolver lowers bare identifiers to \CaseName in enum bodies.
            $caseName = ltrim($expr->name->toString(), '\\');
        } else {
            return null;
        }
        $lc = strtolower($caseName);
        if (\in_array($lc, ['true', 'false', 'null'], true)) {
            return null;
        }
        $enumKey = strtolower($enumFqcn);
        if (!isset($this->enumCases[$enumKey][$caseName])) {
            return null;
        }

        return new Expr\ClassConstFetch(
            new FullyQualified($enumFqcn, $expr->name->getAttributes()),
            new Identifier($caseName, $expr->name->getAttributes()),
            $expr->getAttributes()
        );
    }
}
