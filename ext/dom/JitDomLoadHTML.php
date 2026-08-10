<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\ext\standard\JitIntdiv;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\DomLoadHTMLRuntime;
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

/**
 * LLVM lowering for DOMDocument::loadHTML() (#17954).
 *
 * php-src: ext/dom/php_dom.c — dom_document_load_html
 */
final class JitDomLoadHTML
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DOMDocument::loadHTML() expects receiver and HTML string');
        }

        // Z_PARAM_STR: strict null → TypeError; weak null → DEP then '' → ValueError (#30041 / #22680).
        if (JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant) {
            if ($context->callerStrictTypes) {
                return self::emitNullSourceTypeError($context);
            }
            JitStringBuiltinArg::emitNullStringParamDeprecation(
                $context,
                'DOMDocument::loadHTML',
                0,
                'source'
            );

            return self::emitEmptySourceValueError($context, 'DOMDocument::loadHTML()');
        }
        if (self::isEmptySourceLiteral($args[1])) {
            return self::emitEmptySourceValueError($context, 'DOMDocument::loadHTML()');
        }

        // Try-body SSA boxes null as TYPE_VALUE and often drops isNullConstant (#22680).
        // Guard the VALUE type byte before UserScript can return silent false.
        if (JITVariable::TYPE_VALUE === $args[1]->type) {
            self::emitBoxedNullEmptySourceGuard($context, $args[1], 'DOMDocument::loadHTML()');
        }

        $htmlStr = JitStringBuiltinArg::lowerStrictOrCoercible(
            $context,
            $args[1],
            'DOMDocument::loadHTML',
            0,
            'source'
        );

        if (JitDomLoadHTMLUserScript::shouldUse($context)) {
            return JitDomLoadHTMLUserScript::invoke($context, ...$args);
        }

        DomLoadHTMLRuntime::ensureLinked($context);

        $document = self::loadObjectArg($context, $args[0]);
        $options = $context->getTypeFromString('int64')->constInt(0, false);
        if (isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            $options = JitIntdiv::lowerIntBuiltinArg($context, $args[2], 'DOMDocument::loadHTML()', 2, 'options');
        }

        return $context->builder->call(
            $context->lookupFunction(DomLoadHTMLRuntime::ABI_NAME),
            $document,
            $htmlStr,
            $options
        );
    }

    /** Runtime TYPE_VALUE null → DEP + catchable/abort ValueError; else fall through (#22680). */
    private static function emitBoxedNullEmptySourceGuard(
        Context $context,
        JITVariable $sourceArg,
        string $method
    ): void {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $sourceArg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $typeKind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $nullBlock = BasicBlockHelper::append($context, 'dom_lh_value_null');
        $okBlock = BasicBlockHelper::append($context, 'dom_lh_value_ok');
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
            self::emitNullSourceTypeError($context);
        } else {
            JitStringBuiltinArg::emitNullStringParamDeprecation(
                $context,
                'DOMDocument::loadHTML',
                0,
                'source'
            );
            self::emitEmptySourceValueError($context, $method);
        }
        // emitEmptySourceValueError leaves insert terminated (catchable) or on a dead block.
        // Only continue on the ok path when still open.
        $insert = BasicBlockHelper::tryGetInsertBlock($context);
        if (null !== $insert && null === $insert->getTerminator()) {
            $context->builder->branch($okBlock);
        }
        $context->builder->positionAtEnd($okBlock);
    }

    private static function emitNullSourceTypeError(Context $context): Value
    {
        $message = 'DOMDocument::loadHTML(): Argument #1 ($source) must be of type string, null given';
        \PHPCompiler\JIT\JitNativeString::ensureInsertBlock($context);
        \PHPCompiler\JIT\ExceptionBridge::emitTypeErrorAndAbort($context, $message);

        return $context->getTypeFromString('int1')->constInt(0, false);
    }

    private static function emitEmptySourceValueError(Context $context, string $method): Value
    {
        $message = $method.': Argument #1 ($source) must not be empty';
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        $llvmFunc = BasicBlockHelper::parentFunction($context);
        if (null !== TryCatchHelper::resolveThrowHandler($context)) {
            TryCatchHelper::emitCatchableClassError($context, 'ValueError', $message);

            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        TypeErrorRaise::emitValueError($context, $message);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_type_error'));
        } else {
            $context->builder->call($context->lookupFunction('abort'));
            $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
        }
        $dead = $llvmFunc->appendBasicBlock('dom_load_html_empty_src_dead');
        $context->builder->positionAtEnd($dead);

        return $context->getTypeFromString('int1')->constInt(0, false);
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

        throw new \LogicException('DOMDocument::loadHTML() receiver must be an object');
    }
}
