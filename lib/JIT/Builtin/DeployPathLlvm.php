<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\Context;
use PHPCompiler\Web\DeployRoot;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Thin-AOT LLVM body for __compiler_phpc_deploy_path (#33244 / follow-up of #33225).
 *
 * NestedJIT of {@see \PHPCompiler\ext\standard\DeployPathJitHelper} pulls Web\DeployRoot
 * and SIGSEGVs after c:main_before_php under thin AOT (peer PathJitHelper #26905 /
 * getcwd #26928 / #33217). Emit libc getenv + concat in LLVM; VM SSOT stays DeployRoot /
 * DeployPathJitHelper. php-src: n/a (php-compiler deploy layout #585).
 */
final class DeployPathLlvm
{
    public static function implement(Context $context, LlvmFunction $fn): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);

        $entry = $fn->appendBasicBlock('deploy_llvm_entry');
        $context->builder->positionAtEnd($entry);

        $rel = $fn->getParam(0);
        $fallback = $fn->getParam(1);

        $envName = $context->builder->load(
            $context->constantStringFromString(DeployRoot::ENV)
        );
        // Libc getenv leaf — same as NestedJIT getenv (#29313); avoids NestedJIT of
        // DeployPathJitHelper under thin AOT (#33244).
        $root = StringGetenv::invokeNestedLeaf($context, $envName);

        $missBb = $fn->appendBasicBlock('deploy_llvm_env_miss');
        $hitBb = $fn->appendBasicBlock('deploy_llvm_env_hit');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $root, $strPtr->constNull());
        $context->builder->branchIf($isNull, $missBb, $hitBb);

        $context->builder->positionAtEnd($missBb);
        $context->builder->returnValue($fallback);

        $context->builder->positionAtEnd($hitBb);
        $rootLen = $context->builder->call(
            $context->lookupFunction('__string__strlen'),
            $root
        );
        $emptyBb = $fn->appendBasicBlock('deploy_llvm_env_empty');
        $readyBb = $fn->appendBasicBlock('deploy_llvm_env_ready');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $rootLen, $zero);
        $context->builder->branchIf($isEmpty, $emptyBb, $readyBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue($fallback);

        $context->builder->positionAtEnd($readyBb);
        $slash = $context->builder->load($context->constantStringFromString('/'));

        $relEmptyBb = $fn->appendBasicBlock('deploy_llvm_rel_empty');
        $relJoinBb = $fn->appendBasicBlock('deploy_llvm_rel_join');
        $relLenBb = $fn->appendBasicBlock('deploy_llvm_rel_len');

        $relIsNull = $context->builder->icmp(Builder::INT_EQ, $rel, $strPtr->constNull());
        $context->builder->branchIf($relIsNull, $relEmptyBb, $relLenBb);

        $context->builder->positionAtEnd($relLenBb);
        $relLen = $context->builder->call(
            $context->lookupFunction('__string__strlen'),
            $rel
        );
        $relIsEmpty = $context->builder->icmp(Builder::INT_EQ, $relLen, $zero);
        $context->builder->branchIf($relIsEmpty, $relEmptyBb, $relJoinBb);

        $context->builder->positionAtEnd($relEmptyBb);
        $context->builder->returnValue($root);

        $context->builder->positionAtEnd($relJoinBb);
        $withSlash = JitStringConcat::concat($context, $root, $slash);
        $joined = JitStringConcat::concat($context, $withSlash, $rel);
        $context->builder->returnValue($joined);
    }
}
