<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Config;
use PHPLLVM;

/**
 * LLVM module verify + post-terminator debug scan for {@see Context} (#36387).
 *
 * Extracted from {@see ContextModuleCompileAndOptimize} so verifyModuleOrThrow /
 * debugScanForPostTerminatorInstructions stay a separate TU from compileInPlace /
 * compileCommon (split-TU / size-budget ratchet, #36199 / #36403).
 *
 * Used via {@code use ContextModuleVerify;} on {@see Context}.
 * Compile hub: {@see ContextModuleCompileAndOptimize}; IR opt:
 * {@see ContextModuleOptimizationPasses}.
 *
 * No new C ABI. php-src analogy: Zend does not ship a separate verify TU, but
 * opcache / Optimizer validate IR-shaped structures beside compile
 * (Zend/Optimizer/zend_optimizer.c; LLVMVerifyModule / LLVMVerifyFunction).
 */
trait ContextModuleVerify
{
    /**
     * LLVM verify failures on large modules repeat the same line thousands of times (#36253).
     * Surface the first distinct lines and a best-effort function name instead of megabytes of IR.
     */
    private function verifyModuleOrThrow(): void
    {
        // User-script AOT: skip module verify unless asserts are on — ~110–140ms on MiniWebApp
        // and redundant with aot-smoke / differential gates for the cold-compile target (#36387).
        if ($this->isUserScriptAot()) {
            $assert = Config::getenv('PHP_COMPILER_LLVM_ASSERT');
            if ('1' !== $assert && 'true' !== strtolower((string) $assert)) {
                return;
            }
        }
        $message = '';
        if ($this->module->verify($this->module::VERIFY_ACTION_RETURN, $message)) {
            return;
        }
        $funcName = 'unknown';
        if (preg_match('/@([A-Za-z0-9_.$]+)/', $message, $match)) {
            $funcName = $match[1];
        }
        $uniqueLines = [];
        $seen = [];
        foreach (explode("\n", $message) as $line) {
            if ('' === $line || isset($seen[$line])) {
                continue;
            }
            $seen[$line] = true;
            $uniqueLines[] = $line;
            if (\count($uniqueLines) >= 20) {
                break;
            }
        }
        $dumpPath = Config::getenv('PHP_COMPILER_LLVM_VERIFY_DUMP');
        $failingFns = [];
        if (\is_string($dumpPath) && '' !== $dumpPath) {
            // Module verify's first @name is often a *callee* (e.g. valueDelref), not the
            // broken function. Per-fn LLVMVerifyFunction names the actual offenders (#36382).
            $fn = $this->module->getFirstFunction();
            while (null !== $fn) {
                if ($fn instanceof PHPLLVM\Value\Function_) {
                    $name = '?';
                    try {
                        // Value::getName() calls mistyped LLVMValueGetName; use LLVMGetValueName.
                        $raw = $fn->llvm->lib->LLVMGetValueName($fn->value);
                        $name = \is_object($raw) && method_exists($raw, 'toString')
                            ? (string) $raw->toString()
                            : (string) $raw;
                        if ('' === $name) {
                            $name = '?';
                        }
                    } catch (\Throwable) {
                    }
                    $ok = true;
                    try {
                        // LLVMVerifyFunction: 0 = ok; fromBool inverts LLVMBool.
                        // LLVMReturnStatusAction = 2.
                        $ok = $fn->llvm->fromBool(
                            $fn->llvm->lib->LLVMVerifyFunction($fn->value, 2)
                        );
                    } catch (\Throwable) {
                        $ok = false;
                    }
                    if (!$ok) {
                        $failingFns[] = $name;
                        if (\count($failingFns) <= 2 && '?' !== $name && '' !== $name) {
                            try {
                                $printed = $fn->llvm->lib->LLVMPrintValueToString($fn->value);
                                $ir = \is_object($printed) && method_exists($printed, 'toString')
                                    ? (string) $printed->toString()
                                    : (string) $printed;
                                @file_put_contents($dumpPath.'.'.$name.'.ll', $ir);
                            } catch (\Throwable) {
                            }
                        }
                    }
                }
                $next = $fn->getNext();
                if (null === $next) {
                    break;
                }
                $fn = $next;
            }
            $dump = $message;
            if ([] !== $failingFns) {
                $dump = "Failing functions (".\count($failingFns)."):\n"
                    .implode("\n", $failingFns)
                    ."\n\n".$message;
                $funcName = $failingFns[0];
            }
            @file_put_contents($dumpPath, $dump);
        }
        $head = implode("\n", $uniqueLines);
        $totalLines = substr_count($message, "\n") + ('' !== $message && !str_ends_with($message, "\n") ? 1 : 0);
        $suffix = $totalLines > \count($uniqueLines)
            ? "\n… (".($totalLines - \count($uniqueLines)).' more lines truncated)'
            : '';
        if ([] !== $failingFns) {
            $suffix .= "\nFailing functions: ".implode(', ', \array_slice($failingFns, 0, 12))
                .(\count($failingFns) > 12 ? ' …(+'.(\count($failingFns) - 12).')' : '');
        }
        throw new \RuntimeException(
            "Module verification failed in function {$funcName}:\n{$head}{$suffix}"
        );
    }

    private function debugScanForPostTerminatorInstructions(): void
    {
        $flag = Config::getenv('PHP_COMPILER_DEBUG_LLVM_BLOCKS');
        if ('1' !== $flag && 'true' !== strtolower((string) $flag)) {
            return;
        }
        $function = $this->module->getFirstFunction();
        while (null !== $function) {
            if ($function instanceof \PHPLLVM\Value\Function_) {
                $block = $function->getFirstBasicBlock();
                while (null !== $block) {
                    $terminator = $block->getTerminator();
                    if (null === $terminator) {
                        $block = $block->getNext();
                        continue;
                    }
                    $seenTerminator = false;
                    try {
                        $inst = $block->getFirstInstruction();
                    } catch (\Throwable) {
                        $block = $block->getNext();
                        continue;
                    }
                    while (null !== $inst) {
                        if ($seenTerminator) {
                            fwrite(
                                STDERR,
                                'llvm-block-debug: fn='.$function->getName()
                                .' bb='.$block->getName()
                                ." has instruction after terminator\n"
                            );
                            break;
                        }
                        if ($inst === $terminator) {
                            $seenTerminator = true;
                        } elseif ($inst instanceof \PHPLLVM\Value\Instruction) {
                            try {
                                if ($inst->isABranchInst() || $inst->isAReturnInst() || $inst->isAUnreachableInst()) {
                                    $seenTerminator = true;
                                }
                            } catch (\Throwable) {
                            }
                        }
                        $inst = $inst instanceof \PHPLLVM\Value\Instruction ? $inst->getNext() : null;
                    }
                    $block = $block->getNext();
                }
            }
            $function = $function->getNext();
        }
    }
}
