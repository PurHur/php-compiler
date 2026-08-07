<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for ReflectionClass::isFinal() (#18297, #28135).
 *
 * Native strcmp table — NestedJIT bool/int helpers for this probe fail module verify
 * under thin AOT (ret i64 vs i1 / terminator-in-block). Builtin finals are known at
 * link time; matches FinalClassExtensionCheck::INTERNAL_FINAL + profile-gated GMP
 * (+ Fiber/FiberError from zend_fibers.stub.php; #28389;
 * Socket/AddressInfo from sockets.stub.php; #28391;
 * InflateContext/DeflateContext from zlib.stub.php; #28385;
 * Random\\Randomizer + Engine\\* from random.stub.php; #28387;
 * XMLParser from ext/xml/xml.stub.php; #28386;
 * AllowDynamicProperties/ReturnTypeWillChange/SensitiveParameter/Override/Deprecated
 * from Zend/zend_attributes.stub.php; #28402).
 */
final class ReflectionClassIsFinalRuntime
{
    private const ABI = '__phpc_refl_class_is_final';

    public static function invoke(Context $context, Value $nameStr): Value
    {
        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $nameStr);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        StringCaseCompare::ensureStrcasecmpLinked($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i1, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI, $ft);

        $entry = $fn->appendBasicBlock('refl_class_is_final_entry');
        $context->builder->positionAtEnd($entry);
        $nameArg = $fn->getParam(0);
        $strMap = $context->structFieldMap['__string__'];
        $nameCstr = $context->builder->pointerCast(
            $context->builder->structGep($nameArg, $strMap['value']),
            $i8p
        );

        $finals = self::knownFinalClassNames();
        $trueBlock = $fn->appendBasicBlock('refl_class_final_yes');
        $falseBlock = $fn->appendBasicBlock('refl_class_final_no');
        $checkBlock = $entry;
        $n = \count($finals);
        foreach ($finals as $idx => $className) {
            $context->builder->positionAtEnd($checkBlock);
            $wantStr = $context->builder->load($context->constantStringFromString($className));
            $wantCstr = $context->builder->pointerCast(
                $context->builder->structGep($wantStr, $strMap['value']),
                $i8p
            );
            $cmp = $context->builder->call(
                $context->lookupFunction(StringCaseCompare::ABI_STRCASECMP),
                $nameCstr,
                $wantCstr
            );
            $match = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            $next = ($idx === $n - 1)
                ? $falseBlock
                : $fn->appendBasicBlock('refl_class_final_try_'.($idx + 1));
            $context->builder->branchIf($match, $trueBlock, $next);
            $checkBlock = $next;
        }
        if (0 === $n) {
            $context->builder->positionAtEnd($entry);
            $context->builder->branch($falseBlock);
        }

        $context->builder->positionAtEnd($trueBlock);
        $context->builder->returnValue($i1->constInt(1, false));
        $context->builder->positionAtEnd($falseBlock);
        $context->builder->returnValue($i1->constInt(0, false));

        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * Builtin ZEND_ACC_FINAL class names (php-src stubs / zend_inheritance.c).
     *
     * @return list<string>
     */
    private static function knownFinalClassNames(): array
    {
        $names = [
            'AddressInfo',
            'AllowDynamicProperties',
            'Attribute',
            'Closure',
            'DeflateContext',
            'Fiber',
            'FiberError',
            'Generator',
            'InflateContext',
            'Random\\Engine\\Mt19937',
            'Random\\Engine\\PcgOneseq128XslRr64',
            'Random\\Engine\\Secure',
            'Random\\Engine\\Xoshiro256StarStar',
            'Random\\Randomizer',
            'ReturnTypeWillChange',
            'SensitiveParameter',
            'Socket',
            // php-src ext/xml/xml.stub.php — final class XMLParser (#28386)
            'XMLParser',
        ];
        // php-src 8.4+ final class GMP (ext/gmp/gmp.stub.php; #28135)
        if (CompilerVersion::supportsGmp()) {
            $names[] = 'GMP';
        }
        // php-src Zend/zend_attributes.stub.php — profile-gated finals (#28402)
        if (CompilerVersion::advertisesOverrideAttributeClass()) {
            $names[] = 'Override';
        }
        if (CompilerVersion::advertisesDeprecatedAttributeClass()) {
            $names[] = 'Deprecated';
        }
        if (CompilerVersion::advertisesNoDiscardAttributeClass()) {
            $names[] = 'NoDiscard';
        }

        return $names;
    }
}
