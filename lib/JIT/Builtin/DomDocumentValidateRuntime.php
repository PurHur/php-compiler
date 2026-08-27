<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\dom\JitDomDocumentMethodKernel;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Thin-AOT link for DOMDocument validate/schemaValidate/relaxNG/xinclude/registerNodeClass (#35540).
 */
final class DomDocumentValidateRuntime
{
    public const ABI_VALIDATE = '__phpc_dom_document_validate';

    public const ABI_SCHEMA_VALIDATE = '__phpc_dom_document_schema_validate';

    public const ABI_SCHEMA_VALIDATE_SOURCE = '__phpc_dom_document_schema_validate_source';

    public const ABI_RELAXNG_VALIDATE = '__phpc_dom_document_relaxng_validate';

    public const ABI_RELAXNG_VALIDATE_SOURCE = '__phpc_dom_document_relaxng_validate_source';

    public const ABI_XINCLUDE = '__phpc_dom_document_xinclude';

    public const ABI_REGISTER_NODE_CLASS = '__phpc_dom_document_register_node_class';

    private static int $serial = 0;

    public static function invokeValidate(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_document_validate_invoke');
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'DOMDocument::validate', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        JitDomDocumentMethodKernel::ensureDocumentValidateBridge($context);
        $i1 = $context->builder->call(
            $context->lookupFunction(self::ABI_VALIDATE),
            self::loadObject($context, $args[0])
        );

        return self::boxBool($context, $i1);
    }

    public static function invokeSchemaValidate(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_document_schema_validate_invoke');
        if (!VmClassMethod::requireJitUserArgCountRange(
            $context,
            $args,
            'DOMDocument::schemaValidate',
            1,
            2
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $filename = JitStringBuiltinArg::lower(
            $context,
            $args[1],
            'DOMDocument::schemaValidate',
            0,
            'filename'
        );
        $i64 = $context->getTypeFromString('int64');
        $flags = $i64->constInt(0, false);
        if (isset($args[2])) {
            $flags = JitLongArg::lower(
                $context,
                $args[2],
                'DOMDocument::schemaValidate(): Argument #2 ($flags)'
            );
            if ($flags->typeOf() !== $i64) {
                $flags = $context->builder->sext($flags, $i64);
            }
        }
        JitDomDocumentMethodKernel::ensureDocumentSchemaValidateBridge($context);
        $i1 = $context->builder->call(
            $context->lookupFunction(self::ABI_SCHEMA_VALIDATE),
            self::loadObject($context, $args[0]),
            $filename,
            $flags
        );

        return self::boxBool($context, $i1);
    }

    public static function invokeSchemaValidateSource(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_document_schema_validate_source_invoke');
        if (!VmClassMethod::requireJitUserArgCountRange(
            $context,
            $args,
            'DOMDocument::schemaValidateSource',
            1,
            2
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $source = JitStringBuiltinArg::lower(
            $context,
            $args[1],
            'DOMDocument::schemaValidateSource',
            0,
            'source'
        );
        $i64 = $context->getTypeFromString('int64');
        $flags = $i64->constInt(0, false);
        if (isset($args[2])) {
            $flags = JitLongArg::lower(
                $context,
                $args[2],
                'DOMDocument::schemaValidateSource(): Argument #2 ($flags)'
            );
            if ($flags->typeOf() !== $i64) {
                $flags = $context->builder->sext($flags, $i64);
            }
        }
        JitDomDocumentMethodKernel::ensureDocumentSchemaValidateSourceBridge($context);
        $i1 = $context->builder->call(
            $context->lookupFunction(self::ABI_SCHEMA_VALIDATE_SOURCE),
            self::loadObject($context, $args[0]),
            $source,
            $flags
        );

        return self::boxBool($context, $i1);
    }

    public static function invokeRelaxNGValidate(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_document_relaxng_validate_invoke');
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMDocument::relaxNGValidate',
            1
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $filename = JitStringBuiltinArg::lower(
            $context,
            $args[1],
            'DOMDocument::relaxNGValidate',
            0,
            'filename'
        );
        JitDomDocumentMethodKernel::ensureDocumentRelaxNGValidateBridge($context);
        $i1 = $context->builder->call(
            $context->lookupFunction(self::ABI_RELAXNG_VALIDATE),
            self::loadObject($context, $args[0]),
            $filename
        );

        return self::boxBool($context, $i1);
    }

    public static function invokeRelaxNGValidateSource(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_document_relaxng_validate_source_invoke');
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMDocument::relaxNGValidateSource',
            1
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $source = JitStringBuiltinArg::lower(
            $context,
            $args[1],
            'DOMDocument::relaxNGValidateSource',
            0,
            'source'
        );
        JitDomDocumentMethodKernel::ensureDocumentRelaxNGValidateSourceBridge($context);
        $i1 = $context->builder->call(
            $context->lookupFunction(self::ABI_RELAXNG_VALIDATE_SOURCE),
            self::loadObject($context, $args[0]),
            $source
        );

        return self::boxBool($context, $i1);
    }

    public static function invokeXInclude(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_document_xinclude_invoke');
        if (!VmClassMethod::requireJitUserArgCountRange(
            $context,
            $args,
            'DOMDocument::xinclude',
            0,
            1
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $i64 = $context->getTypeFromString('int64');
        $options = $i64->constInt(0, false);
        if (isset($args[1])) {
            $options = JitLongArg::lower(
                $context,
                $args[1],
                'DOMDocument::xinclude(): Argument #1 ($options)'
            );
            if ($options->typeOf() !== $i64) {
                $options = $context->builder->sext($options, $i64);
            }
        }
        JitDomDocumentMethodKernel::ensureDocumentXIncludeBridge($context);
        $count = $context->builder->call(
            $context->lookupFunction(self::ABI_XINCLUDE),
            self::loadObject($context, $args[0]),
            $options
        );
        $isNeg = $context->builder->icmp(Builder::INT_SLT, $count, $i64->constInt(0, false));
        $slot = JitValueBox::alloc($context);
        $id = (string) (++self::$serial);
        $falseBlock = BasicBlockHelper::append($context, 'dom_xinclude_false_'.$id);
        $intBlock = BasicBlockHelper::append($context, 'dom_xinclude_int_'.$id);
        $done = BasicBlockHelper::append($context, 'dom_xinclude_done_'.$id);
        $context->builder->branchIf($isNeg, $falseBlock, $intBlock);

        $context->builder->positionAtEnd($falseBlock);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($intBlock);
        JitValueBox::writeLong($context, $slot, $count);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    public static function invokeRegisterNodeClass(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_document_register_node_class_invoke');
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMDocument::registerNodeClass',
            2
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        $base = JitStringBuiltinArg::lower(
            $context,
            $args[1],
            'DOMDocument::registerNodeClass',
            0,
            'baseClass'
        );
        $extended = self::nullableStringOrEmpty(
            $context,
            $args[2],
            'DOMDocument::registerNodeClass',
            1,
            'extendedClass'
        );
        JitDomDocumentMethodKernel::ensureDocumentRegisterNodeClassBridge($context);
        $i1 = $context->builder->call(
            $context->lookupFunction(self::ABI_REGISTER_NODE_CLASS),
            self::loadObject($context, $args[0]),
            $base,
            $extended
        );

        return self::boxBool($context, $i1);
    }

    private static function loadObject(Context $context, Variable $receiver): Value
    {
        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            JitValueBox::valuePtrFromVariable($context, $receiver)
        );
    }

    private static function boxBool(Context $context, Value $i1): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $i1);

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    private static function nullableStringOrEmpty(
        Context $context,
        Variable $arg,
        string $function,
        int $argIndex,
        string $paramName
    ): Value {
        if (Variable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            return $context->builder->load($context->constantStringFromString(''));
        }

        return JitStringBuiltinArg::lower($context, $arg, $function, $argIndex, $paramName);
    }
}
