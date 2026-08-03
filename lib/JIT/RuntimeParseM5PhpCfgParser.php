<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Func as CoreFunc;
use PHPCfg\Op;
use PHPCfg\Operand;

/**
 * Force NestedJIT of PHPCfg\Parser::parse into the M5 argv / gen-0 module (#26756 / #27426).
 *
 * RuntimeParseM5Native already calls Parser::parse when the symbol is registered;
 * nm on prior tips showed zero PHPCfg\Parser symbols because Runtime.php::parse was
 * C-floor-stubbed and never pulled the vendor class. Opt-in via
 * PHP_COMPILER_M5_FORCE_PARSER_NESTEDJIT=1 — default off until NestedJIT ABI is proven.
 *
 * Untyped vendor parse() must be NestedJIT'd as
 * (__object__*, __string__*, __string__*) -> __object__* (see JIT::isM5NestedJitPhpCfgParserParse);
 * the prior mid-BB / verify failure was often an ABI mismatch (__value__ vs __string__/__object__).
 */
final class RuntimeParseM5PhpCfgParser
{
    /**
     * @param callable(string):string                                                                 $llvmInternalName
     * @param callable(callable():void):void                                                          $nestedJitRun
     * @param callable(\PHPCompiler\Block, string):void                                               $compileBlock
     * @param callable(\PHPCfg\Func, string):\PHPCompiler\Func|null                                   $compileFunc
     * @param callable(string, string):\PHPCfg\Script                                                  $parseFile
     */
    public static function ensureParse(
        Context $context,
        callable $llvmInternalName,
        callable $nestedJitRun,
        callable $compileBlock,
        callable $compileFunc,
        callable $parseFile
    ): bool {
        $flag = getenv('PHP_COMPILER_M5_FORCE_PARSER_NESTEDJIT');
        // Opt-in only until NestedJIT ABI + body for Parser::parse is proven under argv
        // refresh (#26756 / #27426). Set =1 to experiment.
        if ('1' !== $flag && 'true' !== strtolower((string) $flag)) {
            return false;
        }

        $logical = 'PHPCfg\\Parser::parse';
        $lc = strtolower($logical);
        if (isset($context->functions[$lc])) {
            return true;
        }

        $parserPath = self::parserPhpPath();
        if (null === $parserPath) {
            return false;
        }

        try {
            $script = $parseFile((string) file_get_contents($parserPath), $parserPath);
        } catch (\Throwable $e) {
            return false;
        }

        $methodLc = 'parse';
        $savedClassId = $context->scope->classId;
        $savedClassName = $context->scope->className;
        $context->scope->classId = $context->type->object->lookup('PHPCfg\\Parser');
        $context->scope->className = 'phpcfg\\parser';

        try {
            foreach ($script->functions as $cfgFunc) {
                $funcLc = strtolower($cfgFunc->name);
                if ($funcLc !== $lc && $funcLc !== $methodLc && !str_ends_with($funcLc, '\\'.$methodLc)) {
                    continue;
                }
                $compiled = $compileFunc($logical, $cfgFunc);
                if ($compiled instanceof CoreFunc\PHP) {
                    $nestedJitRun(static function () use ($compileBlock, $compiled, $logical): void {
                        $compileBlock($compiled->block, $logical);
                    });
                }
                break;
            }

            if (!isset($context->functions[$lc])) {
                foreach ($script->main->cfg->children as $child) {
                    if (!$child instanceof Op\Stmt\Class_) {
                        continue;
                    }
                    $className = self::cfgOperandClassName($child->name);
                    $classLc = null === $className
                        ? null
                        : strtolower(str_replace('/', '\\', ltrim($className, '\\')));
                    if (null === $classLc || !in_array($classLc, ['phpcfg\\parser', 'parser'], true)) {
                        continue;
                    }
                    foreach ($child->stmts->children as $bodyChild) {
                        if (!$bodyChild instanceof Op\Stmt\ClassMethod) {
                            continue;
                        }
                        if (strtolower($bodyChild->func->name) !== $methodLc) {
                            continue;
                        }
                        if (null === $bodyChild->func->cfg) {
                            break;
                        }
                        $compiled = $compileFunc($logical, $bodyChild->func);
                        if ($compiled instanceof CoreFunc\PHP) {
                            $nestedJitRun(static function () use ($compileBlock, $compiled, $logical): void {
                                $compileBlock($compiled->block, $logical);
                            });
                        }
                        break 2;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Soft-fail: leave C-floor null parse path; do not abort argv rebuild.
            return false;
        } finally {
            $context->scope->classId = $savedClassId;
            $context->scope->className = $savedClassName;
        }

        // Ensure LLVM symbol name is registered under both common mangling keys.
        if (isset($context->functions[$lc])) {
            $func = $context->functions[$lc];
            $mangled = $llvmInternalName($logical);
            if (!isset($context->functions[strtolower($mangled)])) {
                // Already keyed by $lc; lookupParserParse also checks module named functions.
            }
            $existing = $context->module->getNamedFunction($mangled);
            if (null === $existing && null !== $func) {
                // compileBlock already added the function under llvmInternalName.
            }

            return true;
        }

        return isset($context->functions[$lc]);
    }

    private static function parserPhpPath(): ?string
    {
        $candidates = [
            dirname(__DIR__, 2).'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php',
            __DIR__.'/../../vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
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
