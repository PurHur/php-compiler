<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * User-script AOT for DOMCharacterData::substringData() (php-src characterdata.c).
 *
 * Thin standalone AOT does not register CharacterDataSubstringData on DOMText
 * receivers. Compile-time createTextNode/createComment/createCDATASection data
 * is the SSOT — NestedJIT DomRegistry would need a live node the stand-in
 * object does not carry (#32372).
 *
 * php-src: ext/dom/characterdata.c PHP_METHOD(DOMCharacterData, substringData)
 */
final class JitDomSubstringData
{
    /** Last compile-time CharacterData body from createTextNode/Comment/CDATA. */
    public static ?string $lastMaterializedData = null;

    public static function remember(?string $data): void
    {
        self::$lastMaterializedData = $data;
    }

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_substringdata_cont');
        if (!VmClassMethod::requireExactJitUserArgCount(
            $context,
            $args,
            'DOMCharacterData::substringData',
            2
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        if ($context->callerStrictTypes) {
            foreach ([1 => 'offset', 2 => 'count'] as $i => $param) {
                if (isset($args[$i]) && JITVariable::TYPE_NULL === $args[$i]->type) {
                    JitNativeString::ensureInsertBlock($context);
                    ExceptionBridge::emitTypeErrorAndAbort(
                        $context,
                        sprintf(
                            'DOMCharacterData::substringData(): Argument #%d ($%s) must be of type int, null given',
                            $i,
                            $param
                        )
                    );

                    return self::boxConstantString($context, '');
                }
            }
        }

        $data = self::$lastMaterializedData;
        $offset = $args[1]->compileTimeLong ?? null;
        $count = $args[2]->compileTimeLong ?? null;
        if (null === $data || null === $offset || null === $count) {
            throw new \LogicException(
                'DOMCharacterData::substringData() user-script AOT requires compile-time data, offset, and count'
            );
        }

        $len = \strlen($data);
        if ($offset < 0 || $count < 0 || $offset > $len) {
            TryCatchHelper::emitCatchableClassError(
                $context,
                'DOMException',
                'Index Size Error',
                null,
                '',
                0,
                DomExceptionConstants::INDEX_SIZE_ERR
            );

            return self::boxConstantString($context, '');
        }

        return self::boxConstantString($context, substr($data, $offset, $count));
    }

    private static function boxConstantString(Context $context, string $lit): Value
    {
        $str = $context->builder->load($context->constantStringFromString($lit));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $owned
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }
}
