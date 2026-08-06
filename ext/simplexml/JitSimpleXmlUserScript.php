<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\ext\standard\JitBuiltinWarning;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\UserScriptAotEnv;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * User-script standalone AOT: compile-time SimpleXMLElement via host php-src (#19306, #26863).
 *
 * NestedJIT of VmSimpleXml hangs under user-script AOT, so literal load/construct and
 * child/dim/count/cast folds evaluate on the host at compile time.
 */
final class JitSimpleXmlUserScript
{
    /** Runtime-readable local name on foreach-snapshot SXE objects (#27535). */
    private const BAKED_NAME_PROP = '__phpc_sxe_name';

    /** Runtime-readable string cast on foreach-snapshot SXE objects (#27535). */
    private const BAKED_TEXT_PROP = '__phpc_sxe_text';

    /** @var \SplObjectStorage<JITVariable, \SimpleXMLElement>|null */
    private static ?\SplObjectStorage $trees = null;

    /** @var array<string, \SimpleXMLElement> */
    private static array $treesByToken = [];

    private static ?\SimpleXMLElement $lastTree = null;

    /** Document/root from load_string/construct — property fetch on `$x` without a token (#26863). */
    private static ?\SimpleXMLElement $lastRoot = null;

    private static int $tokenSeq = 0;

    /** Set when tryConstruct saw a compile-time literal rejected by host SimpleXMLElement (#22775). */
    private static bool $lastConstructParseFailed = false;

    /**
     * Pending xpath node-set metadata to attach after Call result assign (#26911).
     *
     * @var array{token: string, elements: list<JITVariable>}|null
     */
    private static ?array $pendingXpathAssign = null;

    /**
     * Pending host SXE token after materializeElement (load/children/attributes/…).
     *
     * @var array{token: string, tree: \SimpleXMLElement}|null
     */
    private static ?array $pendingElementAssign = null;

    /** @var array<string, list<JITVariable>> */
    private static array $xpathListsByToken = [];

    public static function lastConstructParseFailed(): bool
    {
        return self::$lastConstructParseFailed;
    }

    /**
     * simplexml_load_string($data) with compile-time XML literal (#26863).
     */
    public static function tryLoadString(Context $context, JITVariable ...$args): ?Value
    {
        if (\count($args) < 1 || !\extension_loaded('simplexml')) {
            return null;
        }
        $lit = JitStringBuiltinArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString;
        if (null === $lit) {
            return null;
        }
        // Optional class_name must be default / SimpleXMLElement for this fold.
        if (isset($args[1]) && JITVariable::TYPE_NULL !== $args[1]->type) {
            $classLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            if (null === $classLit || ('' !== $classLit && 0 !== strcasecmp($classLit, 'SimpleXMLElement'))) {
                return null;
            }
        }
        $prevInternal = null;
        if (\function_exists('libxml_use_internal_errors')) {
            $prevInternal = \libxml_use_internal_errors(true);
            \libxml_clear_errors();
        }
        try {
            $tree = \simplexml_load_string($lit);
        } catch (\Throwable) {
            $tree = false;
        } finally {
            if (null !== $prevInternal && \function_exists('libxml_use_internal_errors')) {
                \libxml_use_internal_errors($prevInternal);
            }
        }
        if (false === $tree || !($tree instanceof \SimpleXMLElement)) {
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeBool(
                $context,
                $slot,
                $context->getTypeFromString('int1')->constInt(0, false)
            );

            return JitValueBox::normalizeValuePtr($context, $slot);
        }

        return self::materializeElement($context, $tree, true);
    }

    public static function tryConstruct(Context $context, JITVariable ...$args): ?Value
    {
        self::$lastConstructParseFailed = false;
        if (\count($args) < 2 || !\extension_loaded('simplexml')) {
            return null;
        }
        $lit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $lit) {
            return null;
        }
        // Clear host libxml ring so a failed construct does not leak into the compiler process.
        $prevInternal = null;
        if (\function_exists('libxml_use_internal_errors')) {
            $prevInternal = \libxml_use_internal_errors(true);
            \libxml_clear_errors();
        }
        try {
            $tree = new \SimpleXMLElement($lit);
        } catch (\Throwable) {
            self::$lastConstructParseFailed = true;
            if (\function_exists('libxml_clear_errors')) {
                \libxml_clear_errors();
            }

            return null;
        } finally {
            if (null !== $prevInternal && \function_exists('libxml_use_internal_errors')) {
                \libxml_use_internal_errors($prevInternal);
            }
        }
        self::store($args[0], $tree, true);

        return self::nullValue($context);
    }

    /**
     * SimpleXMLElement::children — host child view (#27535).
     * php-src: ext/simplexml/sxe.c — PHP_METHOD(SimpleXMLElement, children)
     */
    public static function tryChildren(Context $context, JITVariable ...$args): ?Value
    {
        if ([] === $args || !\extension_loaded('simplexml')) {
            return null;
        }
        $tree = self::lookup($args[0]);
        if (null === $tree) {
            return null;
        }
        $namespaceOrPrefix = null;
        $isPrefix = true;
        if (isset($args[1]) && JITVariable::TYPE_NULL !== $args[1]->type) {
            $namespaceOrPrefix = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            if (null === $namespaceOrPrefix) {
                return null;
            }
        }
        if (isset($args[2]) && JITVariable::TYPE_NULL !== $args[2]->type) {
            $isPrefix = self::compileTimeBool($context, $args[2]);
            if (null === $isPrefix) {
                return null;
            }
        }
        try {
            if (null !== $namespaceOrPrefix) {
                $view = $tree->children($namespaceOrPrefix, $isPrefix);
            } else {
                $view = $tree->children();
            }
        } catch (\Throwable) {
            return null;
        }
        if (!($view instanceof \SimpleXMLElement)) {
            return self::nullValue($context);
        }

        return self::materializeElement($context, $view);
    }

    /**
     * SimpleXMLElement::attributes — host attribute view (#27535 sibling).
     * php-src: ext/simplexml/sxe.c — PHP_METHOD(SimpleXMLElement, attributes)
     */
    public static function tryAttributes(Context $context, JITVariable ...$args): ?Value
    {
        if ([] === $args || !\extension_loaded('simplexml')) {
            return null;
        }
        $tree = self::lookup($args[0]);
        if (null === $tree) {
            return null;
        }
        $namespaceOrPrefix = null;
        $isPrefix = true;
        if (isset($args[1]) && JITVariable::TYPE_NULL !== $args[1]->type) {
            $namespaceOrPrefix = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            if (null === $namespaceOrPrefix) {
                return null;
            }
        }
        if (isset($args[2]) && JITVariable::TYPE_NULL !== $args[2]->type) {
            $isPrefix = self::compileTimeBool($context, $args[2]);
            if (null === $isPrefix) {
                return null;
            }
        }
        try {
            if (null !== $namespaceOrPrefix) {
                $view = $tree->attributes($namespaceOrPrefix, $isPrefix);
            } else {
                $view = $tree->attributes();
            }
        } catch (\Throwable) {
            return null;
        }
        if (null === $view) {
            return self::nullValue($context);
        }
        if (!($view instanceof \SimpleXMLElement)) {
            return self::nullValue($context);
        }

        return self::materializeElement($context, $view);
    }

    /**
     * SimpleXMLElement::getName — host local name (#27535).
     * php-src: ext/simplexml/sxe.c — PHP_METHOD(SimpleXMLElement, getName)
     *
     * Exact host match only — never lastTree (foreach loop vars would all fold to the
     * last child). Snapshot elements bake the name for runtime reads.
     */
    public static function tryGetName(Context $context, JITVariable ...$args): ?Value
    {
        if ([] === $args || !\extension_loaded('simplexml')) {
            return null;
        }
        $tree = self::lookupExact($args[0]);
        if (null !== $tree) {
            try {
                $name = $tree->getName();
            } catch (\Throwable) {
                return null;
            }

            return self::boxConstantString($context, $name);
        }

        return self::readBakedStringProp($context, $args[0], self::BAKED_NAME_PROP);
    }

    /**
     * Fold (string)$sxe / echo when a host tree is known (#26863).
     * Exact match only — lastTree would mis-fold foreach values (#27535).
     */
    public static function tryToString(Context $context, JITVariable ...$args): ?Value
    {
        if ([] === $args || !\extension_loaded('simplexml')) {
            return null;
        }
        $tree = self::lookupExact($args[0]);
        if (null !== $tree) {
            $text = (string) $tree;

            return self::boxConstantString($context, $text);
        }

        return self::readBakedStringProp($context, $args[0], self::BAKED_TEXT_PROP);
    }

    /**
     * Fold string cast of a value-boxed / object SXE without a class hint (#26863).
     * Exact match only (#27535).
     */
    public static function tryFoldStringCast(Context $context, JITVariable $var): ?JITVariable
    {
        if (!UserScriptAotEnv::isActive() || !\extension_loaded('simplexml')) {
            return null;
        }
        $tree = self::lookupExact($var);
        if (null !== $tree) {
            $text = (string) $tree;

            return new JITVariable(
                $context,
                JITVariable::TYPE_STRING,
                JITVariable::KIND_VALUE,
                $context->builder->load($context->constantStringFromString($text))
            );
        }
        $boxed = self::readBakedStringProp($context, $var, self::BAKED_TEXT_PROP);
        if (null === $boxed) {
            return null;
        }
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $boxed
        );

        return new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $str
        );
    }

    /**
     * Host tree for thin-AOT foreach snapshot (#27535).
     * Prefer exact/token match; fall back to lastTree for load_string results
     * before pending-assign propagation (same as {@see lookup()}).
     */
    public static function hostTreeForForeach(JITVariable $var): ?\SimpleXMLElement
    {
        if (!UserScriptAotEnv::isActive() || !\extension_loaded('simplexml')) {
            return null;
        }

        return self::lookupExact($var) ?? self::$lastTree;
    }

    /** SimpleXMLElement::__get — host child view (#26863). */
    public static function tryGet(Context $context, JITVariable ...$args): ?Value
    {
        if (\count($args) < 2 || !\extension_loaded('simplexml')) {
            return null;
        }
        // Prefer exact/token match; else lastRoot (not lastTree — dim fetch overwrites lastTree).
        $tree = self::lookupExact($args[0]) ?? self::$lastRoot;
        if (null === $tree) {
            return null;
        }
        $name = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $name) {
            return null;
        }
        try {
            $child = $tree->{$name};
        } catch (\Throwable) {
            return null;
        }
        if (!($child instanceof \SimpleXMLElement)) {
            return self::nullValue($context);
        }

        return self::materializeElement($context, $child);
    }

    /** SimpleXMLElement::offsetGet — host dim/attr (#26863). */
    public static function tryOffsetGet(Context $context, JITVariable ...$args): ?Value
    {
        if (\count($args) < 2 || !\extension_loaded('simplexml')) {
            return null;
        }
        // XPath node-sets are arrays. Never dim-fold them as the last host element (#27413).
        if (self::isArrayShapedCountOperand($args[0])) {
            return null;
        }
        $token = $args[0]->compileTimeString;
        if (null !== $token && isset(self::$xpathListsByToken[$token])) {
            return null;
        }
        $tree = self::lookup($args[0]);
        if (null === $tree) {
            return null;
        }
        $dim = self::compileTimeDim($context, $args[1]);
        if (null === $dim) {
            return null;
        }
        try {
            $child = $tree[$dim];
        } catch (\Throwable) {
            return null;
        }
        if (!($child instanceof \SimpleXMLElement)) {
            return self::nullValue($context);
        }

        return self::materializeElement($context, $child);
    }

    /** SimpleXMLElement::count / count($sxe) fold (#26863, #27413). */
    public static function tryCount(Context $context, JITVariable ...$args): ?Value
    {
        if ([] === $args || !\extension_loaded('simplexml')) {
            return null;
        }
        $token = $args[0]->compileTimeString;
        if (null !== $token && isset(self::$xpathListsByToken[$token])) {
            return $context->getTypeFromString('int64')->constInt(
                \count(self::$xpathListsByToken[$token]),
                false
            );
        }
        // XPath node-sets are arrays. Never fall back to lastTree — a missing list
        // token + last matched element yields the element's child count (#27413).
        if (self::isArrayShapedCountOperand($args[0])) {
            return null;
        }
        $tree = self::lookupExact($args[0]);
        if (null === $tree) {
            return null;
        }

        return $context->getTypeFromString('int64')->constInt(\count($tree), false);
    }

    /**
     * Fold count($sxe) when a host tree is known (#26863).
     * XPath node-set Variables must count the list, not fall back to lastTree (#26911, #27413).
     */
    public static function tryFoldCount(Context $context, JITVariable $var): ?Value
    {
        if (!UserScriptAotEnv::isActive() || !\extension_loaded('simplexml')) {
            return null;
        }
        $token = $var->compileTimeString;
        if (null !== $token && isset(self::$xpathListsByToken[$token])) {
            return $context->getTypeFromString('int64')->constInt(
                \count(self::$xpathListsByToken[$token]),
                false
            );
        }
        // Ternary/PHI paths often lose the xpath list token on the count() arg while
        // `$n[$i]` still folds via the named binding. Falling through to lastTree then
        // reports the last matched element's child count (0 for a text leaf, N for a
        // parent) instead of the node-set length — AOT `0|y` / `2|…` vs Zend `1|y` (#27413).
        if (self::isArrayShapedCountOperand($var)) {
            return null;
        }
        $tree = self::lookupExact($var);
        if (null === $tree) {
            return null;
        }

        return $context->getTypeFromString('int64')->constInt(\count($tree), false);
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

    /**
     * Compile-time SimpleXMLElement::registerXPathNamespace via host php-src (#27534).
     * Mutates the host tree so a subsequent literal xpath() fold sees registered prefixes.
     */
    public static function tryRegisterXPathNamespace(Context $context, JITVariable ...$args): ?Value
    {
        if (\count($args) < 3 || !\extension_loaded('simplexml')) {
            return null;
        }
        $tree = self::lookup($args[0]);
        if (null === $tree) {
            return null;
        }
        $prefix = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $prefix) {
            return null;
        }
        $namespace = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
        if (null === $namespace) {
            return null;
        }
        try {
            $tree->registerXPathNamespace($prefix, $namespace);
        } catch (\Throwable) {
            return null;
        }
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt(1, false)
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
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
     * Compile-time SimpleXMLElement::xpath via host php-src (#22720, #26911).
     * Supports false (invalid / undef prefix), empty node-sets, and non-empty
     * node-sets materialized as packed SimpleXMLElement objects for user-script AOT.
     */
    public static function tryXpath(Context $context, JITVariable ...$args): ?Value
    {
        self::$pendingXpathAssign = null;
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

        $elementVars = [];
        foreach ($result as $node) {
            if (!($node instanceof \SimpleXMLElement)) {
                continue;
            }
            $classId = $context->type->object->lookup('SimpleXMLElement');
            $obj = $context->type->object->allocate($classId);
            $context->type->object->markObjectConstructed($obj);
            $receiver = new JITVariable(
                $context,
                JITVariable::TYPE_OBJECT,
                JITVariable::KIND_VALUE,
                $obj
            );
            self::store($receiver, $node);
            $elementVars[] = $receiver;
        }
        if ([] === $elementVars) {
            return HashTableHelper::emptyVariable($context)->value;
        }

        $packed = HashTableHelper::packVariables($context, $elementVars);
        $token = '__phpc_sxml_xpath_'.(++self::$tokenSeq);
        $packed->compileTimeString = $token;
        self::$xpathListsByToken[$token] = $elementVars;
        self::$pendingXpathAssign = [
            'token' => $token,
            'elements' => $elementVars,
        ];

        return $packed->value;
    }

    /** Attach xpath node-set token after Call result assign so `$n[i]` can fold (#26911). */
    public static function applyPendingXpathAssign(JITVariable $result): void
    {
        $pending = self::$pendingXpathAssign;
        self::$pendingXpathAssign = null;
        if (null === $pending) {
            return;
        }
        $result->compileTimeString = $pending['token'];
        self::$xpathListsByToken[$pending['token']] = $pending['elements'];
    }

    /**
     * Fold `$xpathResult[$i]` to the compile-time SimpleXMLElement Variable (#26911).
     */
    public static function tryFoldXpathListDim(
        Context $context,
        JITVariable $container,
        JITVariable $dim
    ): ?JITVariable {
        if (!UserScriptAotEnv::isActive()) {
            return null;
        }
        $token = $container->compileTimeString;
        if (null === $token || !isset(self::$xpathListsByToken[$token])) {
            return null;
        }
        $idx = self::compileTimeDim($context, $dim);
        if (!\is_int($idx)) {
            return null;
        }
        $list = self::$xpathListsByToken[$token];
        if ($idx < 0 || $idx >= \count($list)) {
            return null;
        }

        return $list[$idx];
    }

    private static function materializeElement(Context $context, \SimpleXMLElement $tree, bool $asRoot = false): Value
    {
        $classId = $context->type->object->lookup('SimpleXMLElement');
        $obj = $context->type->object->allocate($classId);
        $context->type->object->markObjectConstructed($obj);
        $receiver = new JITVariable(
            $context,
            JITVariable::TYPE_OBJECT,
            JITVariable::KIND_VALUE,
            $obj
        );
        self::store($receiver, $tree, $asRoot);
        self::bakeElementScalars($context, $receiver, $tree);
        $token = $receiver->compileTimeString;
        if (null !== $token) {
            self::$pendingElementAssign = ['token' => $token, 'tree' => $tree];
        }
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $obj
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    /**
     * Attach host SXE token after Call result assign so foreach/getName can resolve (#27535).
     *
     * @return bool true when a pending element was applied
     */
    public static function applyPendingElementAssign(JITVariable $result): bool
    {
        $pending = self::$pendingElementAssign;
        self::$pendingElementAssign = null;
        if (null === $pending) {
            return false;
        }
        $result->compileTimeString = $pending['token'];
        if (null === self::$trees) {
            self::$trees = new \SplObjectStorage();
        }
        self::$trees[$result] = $pending['tree'];
        self::$treesByToken[$pending['token']] = $pending['tree'];
        self::$lastTree = $pending['tree'];

        return true;
    }

    private static function store(JITVariable $receiver, \SimpleXMLElement $tree, bool $asRoot = false): void
    {
        if (null === self::$trees) {
            self::$trees = new \SplObjectStorage();
        }
        self::$trees[$receiver] = $tree;
        $token = '__phpc_sxml_'.(++self::$tokenSeq);
        $receiver->compileTimeString = $token;
        self::$treesByToken[$token] = $tree;
        self::$lastTree = $tree;
        if ($asRoot) {
            self::$lastRoot = $tree;
        }
    }

    /**
     * Bind a host SXE onto a foreach-snapshot element Variable and bake name/text (#27535).
     *
     * @return string compile-time token
     */
    public static function bindHostTreeForSnapshot(
        Context $context,
        JITVariable $receiver,
        \SimpleXMLElement $tree
    ): string {
        // Isolate — host foreach reuses one SimpleXMLElement mutated each step.
        $isolated = self::isolateHostElement($tree);
        self::store($receiver, $isolated);
        self::bakeElementScalars($context, $receiver, $isolated);

        return (string) $receiver->compileTimeString;
    }

    private static function isolateHostElement(\SimpleXMLElement $tree): \SimpleXMLElement
    {
        $xml = $tree->asXML();
        if (false === $xml || '' === $xml) {
            return clone $tree;
        }
        $copy = \simplexml_load_string($xml);
        if ($copy instanceof \SimpleXMLElement) {
            return $copy;
        }

        return clone $tree;
    }

    private static function bakeElementScalars(Context $context, JITVariable $receiver, \SimpleXMLElement $tree): void
    {
        $obj = JITVariable::KIND_VALUE === $receiver->kind
            ? $receiver->value
            : $context->builder->load($receiver->value);
        self::storeBakedStringProp($context, $obj, self::BAKED_NAME_PROP, $tree->getName());
        self::storeBakedStringProp($context, $obj, self::BAKED_TEXT_PROP, (string) $tree);
    }

    private static function storeBakedStringProp(Context $context, Value $obj, string $prop, string $text): void
    {
        $str = $context->builder->load($context->constantStringFromString($text));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $slot = $context->type->object->propertySlotFor($obj, 'SimpleXMLElement', $prop);
        $voidPtr = $context->getTypeFromString('void*');
        $context->builder->store(
            $context->builder->pointerCast($owned, $voidPtr),
            $slot
        );
    }

    private static function readBakedStringProp(Context $context, JITVariable $receiver, string $prop): ?Value
    {
        if (JITVariable::TYPE_OBJECT !== $receiver->type && JITVariable::TYPE_VALUE !== $receiver->type) {
            return null;
        }
        try {
            if (JITVariable::TYPE_OBJECT === $receiver->type && JITVariable::KIND_VALUE === $receiver->kind) {
                $obj = $receiver->value;
            } elseif (JITVariable::TYPE_OBJECT === $receiver->type) {
                $obj = $context->builder->load($receiver->value);
            } elseif (JITVariable::TYPE_VALUE === $receiver->type) {
                $obj = $context->builder->call(
                    $context->lookupFunction('__value__readObject'),
                    JitValueBox::valuePtrFromVariable($context, $receiver)
                );
            } else {
                return null;
            }
            $slot = $context->type->object->propertySlotFor($obj, 'SimpleXMLElement', $prop);
            $raw = $context->builder->load($slot);
            $strPtr = $context->builder->pointerCast($raw, $context->getTypeFromString('__string__*'));
            $owned = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $strPtr
            );

            return self::boxOwnedString($context, $owned);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function boxConstantString(Context $context, string $text): Value
    {
        $str = $context->builder->load($context->constantStringFromString($text));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );

        return self::boxOwnedString($context, $owned);
    }

    private static function boxOwnedString(Context $context, Value $owned): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $owned
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }

    private static function lookupExact(JITVariable $receiver): ?\SimpleXMLElement
    {
        if (null !== self::$trees && isset(self::$trees[$receiver])) {
            return self::$trees[$receiver];
        }
        $token = $receiver->compileTimeString;
        if (null !== $token && isset(self::$treesByToken[$token])) {
            return self::$treesByToken[$token];
        }

        return null;
    }

    private static function lookup(JITVariable $receiver): ?\SimpleXMLElement
    {
        return self::lookupExact($receiver) ?? self::$lastTree;
    }

    /** Array / value-box operands are never host SXE trees for count() folds (#27413). */
    private static function isArrayShapedCountOperand(JITVariable $var): bool
    {
        if (JITVariable::TYPE_HASHTABLE === $var->type) {
            return true;
        }
        if (0 !== ($var->type & JITVariable::IS_NATIVE_ARRAY)) {
            return true;
        }
        if (JITVariable::TYPE_VALUE === $var->type) {
            return true;
        }

        return JitValueBox::isValueOperand($var);
    }

    /** @return int|string|null */
    private static function compileTimeDim(Context $context, JITVariable $dim)
    {
        if (null !== ($dim->compileTimeLong ?? null)) {
            return (int) $dim->compileTimeLong;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $dim->type && JITVariable::KIND_VALUE === $dim->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($dim->value->value)) {
                return (int) $lib->LLVMConstIntGetSExtValue($dim->value->value);
            }
        }

        return JitStringBuiltinArg::compileTimeLiteral($dim) ?? $dim->compileTimeString;
    }

    private static function compileTimeBool(Context $context, JITVariable $var): ?bool
    {
        if (JITVariable::TYPE_NATIVE_BOOL === $var->type && JITVariable::KIND_VALUE === $var->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($var->value->value)) {
                return 0 !== (int) $lib->LLVMConstIntGetSExtValue($var->value->value);
            }
        }

        return null;
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
