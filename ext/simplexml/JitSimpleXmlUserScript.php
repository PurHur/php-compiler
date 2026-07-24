<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\ext\standard\JitBuiltinWarning;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * User-script standalone AOT: compile-time SimpleXMLElement via host php-src (#19306).
 */
final class JitSimpleXmlUserScript
{
    /** @var \SplObjectStorage<JITVariable, \SimpleXMLElement>|null */
    private static ?\SplObjectStorage $trees = null;

    /** @var array<string, \SimpleXMLElement> */
    private static array $treesByToken = [];

    private static ?\SimpleXMLElement $lastTree = null;

    private static int $tokenSeq = 0;

    public static function tryConstruct(Context $context, JITVariable ...$args): ?Value
    {
        if (\count($args) < 2 || !\extension_loaded('simplexml')) {
            return null;
        }
        $lit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $lit) {
            return null;
        }
        try {
            $tree = new \SimpleXMLElement($lit);
        } catch (\Throwable) {
            return null;
        }
        self::store($args[0], $tree);

        return self::nullValue($context);
    }

    public static function tryAddChild(Context $context, JITVariable ...$args): ?Value
    {
        if (\count($args) < 2) {
            return null;
        }
        $tree = self::lookup($args[0]);
        if (null === $tree) {
            return null;
        }
        $name = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $name) {
            return null;
        }
        $value = null;
        if (isset($args[2])) {
            if (JITVariable::TYPE_NULL === $args[2]->type) {
                $value = null;
            } else {
                $value = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
                if (null === $value) {
                    return null;
                }
            }
        }
        $namespace = null;
        if (isset($args[3])) {
            if (JITVariable::TYPE_NULL === $args[3]->type) {
                $namespace = null;
            } else {
                $namespace = JitStringBuiltinArg::compileTimeLiteral($args[3]) ?? $args[3]->compileTimeString;
                if (null === $namespace) {
                    return null;
                }
            }
        }
        try {
            if (null !== $namespace && '' !== $namespace) {
                $tree->addChild($name, $value ?? '', $namespace);
            } else {
                $tree->addChild($name, $value);
            }
        } catch (\Throwable) {
            return null;
        }

        return self::nullValue($context);
    }

    public static function tryAsXml(Context $context, JITVariable ...$args): ?Value
    {
        if ([] === $args) {
            return null;
        }
        $tree = self::lookup($args[0]);
        if (null === $tree) {
            return null;
        }

        // Optional filename — php-src zim_SimpleXMLElement_asXML (#22006).
        if (isset($args[1]) && JITVariable::TYPE_NULL !== $args[1]->type) {
            $pathLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            if (null !== $pathLit && '' === $pathLit) {
                return null;
            }
            // Serialize at compile time; write at runtime so AOT binaries honor CWD (#22006).
            $xml = $tree->asXML();
            if (false === $xml) {
                $slot = JitValueBox::alloc($context);
                JitValueBox::writeBool(
                    $context,
                    $slot,
                    $context->getTypeFromString('int1')->constInt(0, false)
                );

                return JitValueBox::normalizeValuePtr($context, $slot);
            }
            $pathStr = JitStringBuiltinArg::lowerPath(
                $context,
                $args[1],
                'SimpleXMLElement::asXML',
                0,
                'filename'
            );
            $dataStr = $context->builder->load($context->constantStringFromString($xml));
            $dataOwned = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $dataStr
            );
            $written = $context->builder->call(
                $context->lookupFunction('__compiler_file_put_contents'),
                $pathStr,
                $dataOwned,
                $context->getTypeFromString('int64')->constInt(0, false)
            );
            $ok = $context->builder->icmp(
                \PHPLLVM\Builder::INT_NE,
                $written,
                $context->getTypeFromString('int64')->constInt(-1, true)
            );
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeBool($context, $slot, $ok);

            return JitValueBox::normalizeValuePtr($context, $slot);
        }

        $xml = $tree->asXML();
        if (false === $xml) {
            return null;
        }

        // Match JitDomSaveXMLUserScript::boxConstantString (#18268 / #19306).
        $str = $context->builder->load($context->constantStringFromString($xml));
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

    /**
     * Compile-time SimpleXMLElement::xpath via host php-src (#22720).
     * Supports false (invalid / undef prefix) and empty node-sets; non-empty
     * node results still need runtime materialization.
     */
    public static function tryXpath(Context $context, JITVariable ...$args): ?Value
    {
        if (\count($args) < 2 || !\extension_loaded('simplexml')) {
            return null;
        }
        $tree = self::lookup($args[0]);
        if (null === $tree) {
            return null;
        }
        $path = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $path) {
            return null;
        }
        $warn = null;
        set_error_handler(static function (int $severity, string $message) use (&$warn): bool {
            $warn = $message;

            return true;
        });
        try {
            $result = $tree->xpath($path);
        } catch (\Throwable) {
            restore_error_handler();

            return null;
        }
        restore_error_handler();

        if (false === $result) {
            $msg = 'SimpleXMLElement::xpath(): Invalid expression';
            if (\is_string($warn)) {
                if (str_contains($warn, 'Undefined namespace prefix')) {
                    $msg = 'SimpleXMLElement::xpath(): Undefined namespace prefix';
                } elseif (preg_match('/SimpleXMLElement::xpath\(\):\s*(.+)$/', $warn, $m)) {
                    $msg = 'SimpleXMLElement::xpath(): '.$m[1];
                }
            }
            JitBuiltinWarning::emit($context, $msg);
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeBool(
                $context,
                $slot,
                $context->getTypeFromString('int1')->constInt(0, false)
            );

            return JitValueBox::normalizeValuePtr($context, $slot);
        }
        if ([] === $result) {
            return HashTableHelper::emptyVariable($context)->value;
        }

        // Non-empty node-set: cannot reify SimpleXMLElement handles in user-script AOT yet.
        return null;
    }

    private static function store(JITVariable $receiver, \SimpleXMLElement $tree): void
    {
        if (null === self::$trees) {
            self::$trees = new \SplObjectStorage();
        }
        self::$trees[$receiver] = $tree;
        $token = '__phpc_sxml_'.(++self::$tokenSeq);
        $receiver->compileTimeString = $token;
        self::$treesByToken[$token] = $tree;
        self::$lastTree = $tree;
    }

    private static function lookup(JITVariable $receiver): ?\SimpleXMLElement
    {
        if (null !== self::$trees && isset(self::$trees[$receiver])) {
            return self::$trees[$receiver];
        }
        $token = $receiver->compileTimeString;
        if (null !== $token && isset(self::$treesByToken[$token])) {
            return self::$treesByToken[$token];
        }

        return self::$lastTree;
    }

    private static function nullValue(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }
}
