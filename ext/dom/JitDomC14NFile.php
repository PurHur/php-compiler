<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomC14NFileRuntime;
use PHPCompiler\JIT\Builtin\StringFilePutContents;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * LLVM lowering for DOMNode::C14NFile() (#32964).
 *
 * Fold documentElement / annotated-node C14N via {@see JitDomC14N} when loadXML
 * stamped a node path, then write through `__compiler_file_put_contents` (URI may
 * be runtime — sys_get_temp_dir() concat). Else {@see DomC14NFileRuntime}.
 *
 * @see php-src ext/dom/node.c — dom_node_c14n / C14NFile
 */
final class JitDomC14NFile
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_c14nfile_invoke_cont');
        if (!VmClassMethod::requireJitUserArgCountRange(
            $context,
            $args,
            'DOMNode::C14NFile',
            1,
            5
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        if (\count($args) < 2) {
            throw new \LogicException('DOMNode::C14NFile() expects receiver and uri');
        }

        $canonical = JitDomC14N::tryFoldCanonical($args[0], $args[2] ?? null);
        if (null !== $canonical) {
            StringFilePutContents::implement($context);
            $pathStr = self::loadStringArg($context, $args[1]);
            $bodyStr = $context->builder->load($context->constantStringFromString($canonical));
            $bodyOwned = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $bodyStr
            );
            $flags = $context->context->int64Type()->constInt(0, false);
            $written = $context->builder->call(
                $context->lookupFunction('__compiler_file_put_contents'),
                $pathStr,
                $bodyOwned,
                $flags
            );
            // Zend returns bytes written; fold body length matches successful write.
            $len = \strlen($canonical);
            $failed = $context->builder->icmp(
                \PHPLLVM\Builder::INT_SLT,
                $written,
                $context->context->int64Type()->constInt(0, false)
            );
            $id = 'c14nfile_fold_'.spl_object_id($context);
            $slot = JitValueBox::alloc($context);
            $failBlock = BasicBlockHelper::append($context, $id.'_fail');
            $okBlock = BasicBlockHelper::append($context, $id.'_ok');
            $doneBlock = BasicBlockHelper::append($context, $id.'_done');
            $context->builder->branchIf($failed, $failBlock, $okBlock);

            $context->builder->positionAtEnd($failBlock);
            JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($okBlock);
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                JitValueBox::pointer($context, $slot),
                $context->context->int64Type()->constInt($len, true)
            );
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);

            return JitValueBox::normalizeValuePtr($context, $slot);
        }

        DomC14NFileRuntime::ensureLinked($context);
        $exclusive = self::exclusiveAsI64($context, $args[2] ?? null);
        $raw = $context->builder->call(
            $context->lookupFunction(DomC14NFileRuntime::ABI_NAME),
            self::loadObjectArg($context, $args[0]),
            self::loadStringArg($context, $args[1]),
            $exclusive
        );
        $failed = $context->builder->icmp(
            \PHPLLVM\Builder::INT_SLT,
            $raw,
            $context->context->int64Type()->constInt(0, false)
        );
        $id = 'c14nfile_rt_'.spl_object_id($context);
        $slot = JitValueBox::alloc($context);
        $failBlock = BasicBlockHelper::append($context, $id.'_fail');
        $okBlock = BasicBlockHelper::append($context, $id.'_ok');
        $doneBlock = BasicBlockHelper::append($context, $id.'_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            JitValueBox::pointer($context, $slot),
            $raw
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    private static function exclusiveAsI64(Context $context, ?JITVariable $arg): Value
    {
        if (null === $arg) {
            return $context->context->int64Type()->constInt(0, false);
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $arg->type) {
            $raw = $context->helper->loadValue($arg);
            if (method_exists($raw, 'isConstant') && $raw->isConstant() && method_exists($raw, 'getConstantValue')) {
                return $context->context->int64Type()->constInt(
                    ((int) $raw->getConstantValue() !== 0) ? 1 : 0,
                    false
                );
            }

            return $context->builder->zExt($raw, $context->context->int64Type());
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && null !== $arg->compileTimeLong) {
            return $context->context->int64Type()->constInt(0 !== $arg->compileTimeLong ? 1 : 0, false);
        }

        return $context->context->int64Type()->constInt(0, false);
    }

    private static function loadObjectArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $arg)
            );
        }

        throw new \LogicException('DOMNode::C14NFile() receiver must be an object');
    }

    private static function loadStringArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_STRING === $arg->type) {
            return $context->helper->loadValue($arg);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            JitValueBox::valuePtrFromVariable($context, $arg)
        );
    }
}
