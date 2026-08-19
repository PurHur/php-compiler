<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\DomLoadXMLRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for DOMDocument::loadXML() (#18268, #19796). */
final class JitDomLoadXML
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::loadXML() expects receiver and XML string');
        }

        // Z_PARAM_STR: strict null → TypeError; weak null → DEP then '' (#30041).
        // Empty '' (after weak coerce or literal) → ValueError (#22680).
        if (JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant) {
            if ($context->callerStrictTypes) {
                return self::emitNullSourceTypeError($context, 'DOMDocument::loadXML');
            }
            JitStringBuiltinArg::emitNullStringParamDeprecation(
                $context,
                'DOMDocument::loadXML',
                0,
                'source'
            );

            return self::emitEmptySourceValueError($context);
        }
        if (self::isEmptySourceLiteral($args[1])) {
            return self::emitEmptySourceValueError($context);
        }

        if (JITVariable::TYPE_VALUE === $args[1]->type) {
            self::emitBoxedNullEmptySourceGuard($context, $args[1]);
        }

        $xmlStr = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'DOMDocument::loadXML',
            0,
            'source'
        );

        if (JitDomLoadXMLUserScript::shouldUse($context)) {
            $us = JitDomLoadXMLUserScript::tryInvoke($context, ...$args);
            if (null !== $us) {
                return $us;
            }
        }

        $xmlLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        $xmlSource = null;
        if (null !== $xmlLit) {
            $xmlSource = VmDom::stripLeadingUtf8Bom($xmlLit);
            $xmlLit = ltrim($xmlSource);
        }
        if (
            null !== $xmlLit
            && '' !== $xmlLit
            && '<' === $xmlLit[0]
            && !JitDomLoadXMLUserScript::xmlContainsInterElementBlankText($xmlLit)
        ) {
            JitDomLoadXMLUserScript::rememberCompileTimeXml($xmlLit, sourceXml: $xmlSource);
        }

        DomLoadXMLRuntime::ensureLinked($context);

        $document = self::loadObjectArg($context, $args[0]);
        $options = $context->getTypeFromString('int64')->constInt(0, false);
        if (isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            $options = JitIntdiv::lowerIntBuiltinArg($context, $args[2], 'DOMDocument::loadXML()', 2, 'options');
        }
        $raw = $context->builder->call(
            $context->lookupFunction(DomLoadXMLRuntime::ABI_NAME),
            $document,
            $xmlStr,
            $options
        );
        $slot = JitValueBox::alloc($context);
        $i32 = $context->getTypeFromString('int32');
        $boolArg = 'int1' === $context->getStringFromType($raw->typeOf())
            ? $context->builder->zext($raw, $i32)
            : $raw;
        JitValueBox::writeBool($context, $slot, $boolArg);

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    private static function emitBoxedNullEmptySourceGuard(Context $context, JITVariable $sourceArg): void
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $sourceArg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $typeKind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $nullBlock = BasicBlockHelper::append($context, 'dom_lx_value_null');
        $okBlock = BasicBlockHelper::append($context, 'dom_lx_value_ok');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeKind,
                $i8->constInt(VmVariable::TYPE_NULL, false)
            ),
            $nullBlock,
            $okBlock
        );
        $context->builder->positionAtEnd($nullBlock);
        if ($context->callerStrictTypes) {
            self::emitNullSourceTypeError($context, 'DOMDocument::loadXML');
        } else {
            JitStringBuiltinArg::emitNullStringParamDeprecation(
                $context,
                'DOMDocument::loadXML',
                0,
                'source'
            );
            self::emitEmptySourceValueError($context);
        }
        $insert = BasicBlockHelper::tryGetInsertBlock($context);
        if (null !== $insert && null === $insert->getTerminator()) {
            $context->builder->branch($okBlock);
        }
        $context->builder->positionAtEnd($okBlock);
    }

    private static function emitNullSourceTypeError(Context $context, string $function): Value
    {
        $message = $function.'(): Argument #1 ($source) must be of type string, null given';
        \PHPCompiler\JIT\JitNativeString::ensureInsertBlock($context);
        \PHPCompiler\JIT\ExceptionBridge::emitTypeErrorAndAbort($context, $message);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int32')->constInt(0, false));

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    private static function emitEmptySourceValueError(Context $context): Value
    {
        $message = 'DOMDocument::loadXML(): Argument #1 ($source) must not be empty';
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $llvmFunc = BasicBlockHelper::parentFunction($context);
        if (null !== TryCatchHelper::resolveThrowHandler($context)) {
            TryCatchHelper::emitCatchableClassError($context, 'ValueError', $message);

            return $context->getTypeFromString('__value__*')->constNull();
        }
        TypeErrorRaise::emitValueError($context, $message);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_type_error'));
        } else {
            $context->builder->call($context->lookupFunction('abort'));
            $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        }
        $dead = $llvmFunc->appendBasicBlock('dom_load_xml_empty_src_dead');
        $context->builder->positionAtEnd($dead);
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int32')->constInt(0, false));

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    private static function isEmptySourceLiteral(JITVariable $sourceArg): bool
    {
        $lit = JitStringBuiltinArg::compileTimeLiteral($sourceArg) ?? $sourceArg->compileTimeString;

        return null !== $lit && '' === $lit;
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

        throw new \LogicException('DOMDocument::loadXML() receiver must be an object');
    }
}
