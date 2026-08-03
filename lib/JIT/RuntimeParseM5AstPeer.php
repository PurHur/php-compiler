<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Func as CoreFunc;
use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Force NestedJIT of {@see M5ParserAstPeer} methods into the M5 argv / gen-0 module (#27426).
 *
 * PHPCfg\Parser::parse (FORCE_PARSER) calls `$this->astParser->parse($code)` then
 * `$this->astTraverser->traverse($ast)` / `$this->magicStringResolver->beginCompilationUnit`.
 * C-floor wires M5ParserAstPeer for all three; without NestedJIT'd methods the call
 * aborts. Opt-in via the same PHP_COMPILER_M5_FORCE_PARSER_NESTEDJIT flag as
 * {@see RuntimeParseM5PhpCfgParser}.
 *
 * NestedJIT **every** class method (not only the public Parser surface): `parse()` calls
 * private helpers (`stripLeadingPreamble`, `tryEchoStringAst`, …). Skipping those left
 * call sites unresolved and made ensureMethods soft-fail (#27426 post-#27465).
 */
final class RuntimeParseM5AstPeer
{
    /** Public surface that must be present for ensureMethods to count as success. */
    private const REQUIRED_SURFACE = ['parse', 'traverse', 'addvisitor', 'begincompilationunit'];

    /**
     * @param callable(string):string                               $llvmInternalName
     * @param callable(callable():void):void                        $nestedJitRun
     * @param callable(\PHPCompiler\Block, string):void             $compileBlock
     * @param callable(\PHPCfg\Func, string):\PHPCompiler\Func|null $compileFunc
     * @param callable(string, string):\PHPCfg\Script               $parseFile
     */
    public static function ensureMethods(
        Context $context,
        callable $llvmInternalName,
        callable $nestedJitRun,
        callable $compileBlock,
        callable $compileFunc,
        callable $parseFile
    ): bool {
        $flag = getenv('PHP_COMPILER_M5_FORCE_PARSER_NESTEDJIT');
        if ('1' !== $flag && 'true' !== strtolower((string) $flag)) {
            return false;
        }

        $path = self::peerPhpPath();
        if (null === $path) {
            return false;
        }

        try {
            $script = $parseFile((string) file_get_contents($path), $path);
        } catch (\Throwable $e) {
            return false;
        }

        $registered = [];
        $savedClassId = $context->scope->classId;
        $savedClassName = $context->scope->className;
        $context->scope->classId = $context->type->object->lookup(M5ParserAstPeer::class);
        $context->scope->className = 'phpcompiler\\jit\\m5parserastpeer';

        try {
            foreach ($script->main->cfg->children as $child) {
                if (!$child instanceof Op\Stmt\Class_) {
                    continue;
                }
                $className = self::cfgOperandClassName($child->name);
                $classLc = null === $className
                    ? null
                    : strtolower(str_replace('/', '\\', ltrim($className, '\\')));
                if (null === $classLc
                    || !in_array($classLc, ['phpcompiler\\jit\\m5parserastpeer', 'm5parserastpeer'], true)
                ) {
                    continue;
                }
                foreach ($child->stmts->children as $bodyChild) {
                    if (!$bodyChild instanceof Op\Stmt\ClassMethod) {
                        continue;
                    }
                    if (null === $bodyChild->func->cfg) {
                        continue;
                    }
                    $methodName = $bodyChild->func->name;
                    $methodLc = strtolower($methodName);
                    $logical = M5ParserAstPeer::class.'::'.$methodName;
                    $lc = strtolower($logical);
                    if (isset($context->functions[$lc])) {
                        $registered[$methodLc] = true;
                        continue;
                    }
                    try {
                        $compiled = $compileFunc($logical, $bodyChild->func);
                        if ($compiled instanceof CoreFunc\PHP) {
                            $nestedJitRun(static function () use ($compileBlock, $compiled, $logical): void {
                                $compileBlock($compiled->block, $logical);
                            });
                        }
                    } catch (\Throwable $e) {
                        // Soft-fail one method; keep trying siblings (helpers may still land).
                        continue;
                    }
                    if (isset($context->functions[$lc])) {
                        $registered[$methodLc] = true;
                    }
                }
                break;
            }
        } catch (\Throwable $e) {
            // fall through — return based on surface completeness
        } finally {
            $context->scope->classId = $savedClassId;
            $context->scope->className = $savedClassName;
        }

        foreach (self::REQUIRED_SURFACE as $need) {
            if (!isset($registered[$need])) {
                return false;
            }
        }

        return true;
    }

    private static function peerPhpPath(): ?string
    {
        $path = __DIR__.'/M5ParserAstPeer.php';

        return is_file($path) ? $path : null;
    }

    private static function cfgOperandClassName(Operand $operand): ?string
    {
        if ($operand instanceof Operand\Literal && is_string($operand->value)) {
            return $operand->value;
        }
        if ($operand instanceof Operand\Variable) {
            return self::cfgOperandClassName($operand->name);
        }

        return null;
    }
}
