<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM Dom\Attr::rename for thin user-script AOT (#27108 / re-#21083).
 *
 * php-src: ext/dom/element.c — Dom\Element::rename attribute branch
 *
 * Dup detection uses the compile-time attribute cache only (not fetch/orphan
 * globals) so CFG block order cannot poison try-body renames when
 * createAttribute is lowered earlier in the same function.
 */
final class JitDomAttrRename
{
    private const CLASS_ATTR = 'Dom\\Attr';

    private const PROP_NODE_NAME = 'nodeName';

    private const PROP_NAME = 'name';

    private const PROP_NAMESPACE_URI = 'namespaceURI';

    private const PROP_LOCAL_NAME = 'localName';

    private const PROP_PREFIX = 'prefix';

    /** @var null|array{0: string, 1: string} last getAttributeNode key for cache rekey */
    private static ?array $lastAttrKey = null;

    /** When true, skip cache rekey (createAttribute orphan; #27108). */
    private static bool $lastAttrIsOrphan = false;

    public static function rememberFetchedKey(string $namespace, string $localName): void
    {
        self::$lastAttrKey = [$namespace, $localName];
        self::$lastAttrIsOrphan = false;
    }

    /** @return null|array{0: string, 1: string} */
    public static function lastFetchedKey(): ?array
    {
        return self::$lastAttrKey;
    }

    public static function lastAttrIsOrphan(): bool
    {
        return self::$lastAttrIsOrphan;
    }

    public static function rememberOrphan(): void
    {
        // Do not clear lastAttrKey — other rename sites in this function may still
        // need it for rekey when their blocks lower after createAttribute (#27108).
        self::$lastAttrIsOrphan = true;
    }

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 3) {
            throw new \ArgumentCountError(
                'Dom\\Attr::rename() expects exactly 2 arguments, '.(\count($args) - 1).' given'
            );
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_attr_rename_cont');

        $nsLit = self::nullableStringLit($args[1]);
        $qLit = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
        if (null === $qLit) {
            throw new \LogicException('Dom\\Attr::rename() requires a compile-time qualifiedName in this AOT path (#27108)');
        }

        $nsArg = (null === $nsLit || '' === $nsLit) ? '' : $nsLit;
        $pos = strpos($qLit, ':');
        $prefix = false === $pos ? '' : substr($qLit, 0, $pos);
        $local = false === $pos ? $qLit : substr($qLit, $pos + 1);

        $isNoopRename = null !== self::$lastAttrKey
            && self::$lastAttrKey[0] === $nsArg
            && self::$lastAttrKey[1] === $local;

        // Dup vs any other present Attr — independent of orphan/fetch globals (#27108).
        if (!$isNoopRename && DomUserScriptAttributeCacheLlvm::hasPresentLiteral($nsArg, $local)) {
            self::emitDomException(
                $context,
                'An attribute with the given name in the given namespace already exists',
                DomExceptionConstants::INVALID_MODIFICATION_ERR
            );
            $slot = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeNull'),
                JitValueBox::pointer($context, $slot)
            );

            return JitValueBox::normalizeValuePtr($context, JitValueBox::pointer($context, $slot));
        }

        $attr = self::loadObjectArg($context, $args[0]);
        // Living Attr.name is QName (#26024).
        self::storeString($context, $attr, self::PROP_NODE_NAME, $qLit);
        self::storeString($context, $attr, self::PROP_NAME, $qLit);
        self::storeString($context, $attr, self::PROP_LOCAL_NAME, $local);
        self::storeString($context, $attr, self::PROP_PREFIX, $prefix);
        self::storeString($context, $attr, self::PROP_NAMESPACE_URI, $nsArg);

        $isOrphan = self::$lastAttrIsOrphan;
        if ($isOrphan) {
            // Consume one orphan rename so later attached renames can still rekey.
            self::$lastAttrIsOrphan = false;
        }
        if (!$isOrphan && null !== self::$lastAttrKey) {
            [$oldNs, $oldLocal] = self::$lastAttrKey;
            if ($oldNs !== $nsArg || $oldLocal !== $local) {
                DomUserScriptAttributeCacheLlvm::rekeyLiteral(
                    $context,
                    $oldNs,
                    $oldLocal,
                    $nsArg,
                    $local,
                    $attr
                );
            }
            self::$lastAttrKey = [$nsArg, $local];
        }

        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return JitValueBox::normalizeValuePtr($context, JitValueBox::pointer($context, $slot));
    }

    private static function nullableStringLit(JITVariable $arg): ?string
    {
        if (JITVariable::TYPE_NULL === $arg->type || $arg->isNullConstant) {
            return null;
        }

        return JitStringBuiltinArg::compileTimeLiteral($arg) ?? $arg->compileTimeString;
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

        throw new \LogicException('Dom\\Attr::rename() receiver must be an object');
    }

    private static function storeString(Context $context, Value $obj, string $prop, string $lit): void
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup(self::CLASS_ATTR);
        if (!$objectType->hasProperty($classId, $prop)) {
            $objectType->defineProperty($classId, $prop, JITVariable::TYPE_STRING);
        }
        $str = $context->builder->load($context->constantStringFromString($lit));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $propVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $owned);
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, self::CLASS_ATTR, $prop),
            $propVar,
            JITVariable::TYPE_STRING
        );
    }

    private static function emitDomException(Context $context, string $message, int $code): void
    {
        TryCatchHelper::emitCatchableClassError(
            $context,
            'DOMException',
            $message,
            null,
            '',
            0,
            $code
        );
    }
}
