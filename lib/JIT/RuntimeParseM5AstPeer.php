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
 */
final class RuntimeParseM5AstPeer
{
    private const METHODS = ['parse', 'traverse', 'addvisitor', 'begincompilationunit'];

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

        $any = false;
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
                    $methodLc = strtolower($bodyChild->func->name);
                    if (!in_array($methodLc, self::METHODS, true)) {
                        continue;
                    }
                    if (null === $bodyChild->func->cfg) {
                        continue;
                    }
                    $logical = M5ParserAstPeer::class.'::'.$bodyChild->func->name;
                    $lc = strtolower($logical);
                    if (isset($context->functions[$lc])) {
                        $any = true;
                        continue;
                    }
                    $compiled = $compileFunc($logical, $bodyChild->func);
                    if ($compiled instanceof CoreFunc\PHP) {
                        $nestedJitRun(static function () use ($compileBlock, $compiled, $logical): void {
                            $compileBlock($compiled->block, $logical);
                        });
                    }
                    if (isset($context->functions[$lc])) {
                        $any = true;
                    }
                }
                break;
            }
        } catch (\Throwable $e) {
            return $any;
        } finally {
            $context->scope->classId = $savedClassId;
            $context->scope->className = $savedClassName;
        }

        return $any;
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
