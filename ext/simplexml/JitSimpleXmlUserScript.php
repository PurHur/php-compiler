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

    /** Attr + numeric dim map for runtime isset/empty (#34555). */
    private const BAKED_DIMS_PROP = '__phpc_sxe_dims';

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
        $hostErrors = [];
        if (\function_exists('libxml_use_internal_errors')) {
            $prevInternal = \libxml_use_internal_errors(true);
            \libxml_clear_errors();
        }
        try {
            $tree = \simplexml_load_string($lit);
            if (\function_exists('libxml_get_errors')) {
                $hostErrors = \libxml_get_errors();
            }
        } catch (\Throwable) {
            $tree = false;
            if (\function_exists('libxml_get_errors')) {
                $hostErrors = \libxml_get_errors();
            }
        } finally {
            if (null !== $prevInternal && \function_exists('libxml_use_internal_errors')) {
                \libxml_use_internal_errors($prevInternal);
            }
        }
        if (false === $tree || !($tree instanceof \SimpleXMLElement)) {
            // php-src php_libxml_error_handler surface — not a silent false fold (#31183).
            self::emitLoadStringParserWarnings($context, $lit, $hostErrors);
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

    /**
     * simplexml_load_file($filename) with compile-time path literal (#34454).
     *
     * php-src ext/simplexml/simplexml.c PHP_FUNCTION(simplexml_load_file) —
     * NestedJIT of VmSimpleXml hangs under user-script AOT (same as load_string #26863),
     * so fold via host simplexml_load_file + materializeElement.
     */
    public static function tryLoadFile(Context $context, JITVariable ...$args): ?Value
    {
        if (!UserScriptAotEnv::isActive() || \count($args) < 1 || !\extension_loaded('simplexml')) {
            return null;
        }
        $path = JitStringBuiltinArg::compileTimeLiteral($args[0]) ?? $args[0]->compileTimeString;
        if (null === $path) {
            return null;
        }
        if (isset($args[1]) && JITVariable::TYPE_NULL !== $args[1]->type) {
            $classLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            if (null === $classLit || ('' !== $classLit && 0 !== strcasecmp($classLit, 'SimpleXMLElement'))) {
                return null;
            }
        }
        $prevInternal = null;
        $hostErrors = [];
        if (\function_exists('libxml_use_internal_errors')) {
            $prevInternal = \libxml_use_internal_errors(true);
            \libxml_clear_errors();
        }
        try {
            $tree = \simplexml_load_file($path);
            if (\function_exists('libxml_get_errors')) {
                $hostErrors = \libxml_get_errors();
            }
        } catch (\Throwable) {
            $tree = false;
            if (\function_exists('libxml_get_errors')) {
                $hostErrors = \libxml_get_errors();
            }
        } finally {
            if (null !== $prevInternal && \function_exists('libxml_use_internal_errors')) {
                \libxml_use_internal_errors($prevInternal);
            }
        }
        if (false === $tree || !($tree instanceof \SimpleXMLElement)) {
            if (!is_file($path) || !is_readable($path)) {
                JitBuiltinWarning::emit(
                    $context,
                    'simplexml_load_file(): I/O warning : failed to load external entity "'.$path.'"'
                );
            } else {
                // php-src locus is "path:line" for file loads (#31183 sibling).
                self::emitLoadFileParserWarnings($context, $path, $hostErrors);
            }
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

    /**
     * simplexml_import_dom($node) from a compile-time loadXML document (#34419).
     *
     * php-src ext/simplexml/simplexml.c PHP_FUNCTION(simplexml_import_dom) —
     * libxml node of a DOMDocument is the document element; host simplexml_load_string
     * on the remembered loadXML literal matches Zend for the user-script path.
     */
    public static function tryImportDom(Context $context, JITVariable ...$args): ?Value
    {
        if (!UserScriptAotEnv::isActive() || \count($args) < 1 || !\extension_loaded('simplexml')) {
            return null;
        }
        if (isset($args[1]) && JITVariable::TYPE_NULL !== $args[1]->type) {
            $classLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            if (null === $classLit || ('' !== $classLit && 0 !== strcasecmp($classLit, 'SimpleXMLElement'))) {
                return null;
            }
        }
        $xml = \PHPCompiler\ext\dom\JitDomLoadXMLUserScript::compileTimeXmlFor($args[0])
            ?? \PHPCompiler\ext\dom\JitDomLoadXMLUserScript::lastCompileTimeXml();
        if (null === $xml || '' === trim($xml)) {
            return null;
        }
        $prevInternal = null;
        if (\function_exists('libxml_use_internal_errors')) {
            $prevInternal = \libxml_use_internal_errors(true);
            \libxml_clear_errors();
        }
        try {
            $tree = \simplexml_load_string($xml);
        } catch (\Throwable) {
            $tree = false;
        } finally {
            if (null !== $prevInternal && \function_exists('libxml_use_internal_errors')) {
                \libxml_use_internal_errors($prevInternal);
            }
        }
        if (false === $tree || !($tree instanceof \SimpleXMLElement)) {
            return null;
        }

        return self::materializeElement($context, $tree, true);
    }

    public static function tryConstruct(Context $context, JITVariable ...$args): ?Value
    {
        self::$lastConstructParseFailed = false;
        if (\count($args) < 2 || !\extension_loaded('simplexml')) {
            return null;
        }
        // Soft-null $data — E_DEPRECATED then empty parse → Exception (#31514 / sxe.c).
        if (JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant) {
            JitStringBuiltinArg::lowerTrimFamilyString(
                $context,
                $args[1],
                'SimpleXMLElement::__construct',
                0,
                'data'
            );
            self::$lastConstructParseFailed = true;

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
        // php-src simplexml.stub.php: bool $isPrefix = false (URI when omitted; #34554).
        $isPrefix = false;
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
        // php-src simplexml.stub.php: bool $isPrefix = false (URI when omitted; #34554).
        $isPrefix = false;
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
     * SimpleXMLElement::getNamespaces — host sxe_add_namespaces (php-src ext/simplexml/sxe.c).
     * Exact match only — lastTree would mis-fold foreach/child views.
     */
    public static function tryGetNamespaces(Context $context, JITVariable ...$args): ?Value
    {
        if ([] === $args || !\extension_loaded('simplexml')) {
            return null;
        }
        $tree = self::lookupExact($args[0]);
        if (null === $tree) {
            return null;
        }
        $recursive = false;
        if (isset($args[1]) && JITVariable::TYPE_NULL !== $args[1]->type) {
            $parsed = self::compileTimeBool($context, $args[1]);
            if (null === $parsed) {
                return null;
            }
            $recursive = $parsed;
        }
        try {
            $map = $tree->getNamespaces($recursive);
        } catch (\Throwable) {
            return null;
        }
        if (!\is_array($map)) {
            return null;
        }

        return self::boxHostStringMap($context, $map);
    }

    /**
     * SimpleXMLElement::getDocNamespaces — host sxe_add_registered_namespaces
     * (php-src ext/simplexml/sxe.c PHP_METHOD(SimpleXMLElement, getDocNamespaces)).
     */
    public static function tryGetDocNamespaces(Context $context, JITVariable ...$args): ?Value
    {
        if ([] === $args || !\extension_loaded('simplexml')) {
            return null;
        }
        $tree = self::lookupExact($args[0]);
        if (null === $tree) {
            return null;
        }
        $recursive = false;
        $fromRoot = true;
        if (isset($args[1]) && JITVariable::TYPE_NULL !== $args[1]->type) {
            $parsed = self::compileTimeBool($context, $args[1]);
            if (null === $parsed) {
                return null;
            }
            $recursive = $parsed;
        }
        if (isset($args[2]) && JITVariable::TYPE_NULL !== $args[2]->type) {
            $parsed = self::compileTimeBool($context, $args[2]);
            if (null === $parsed) {
                return null;
            }
            $fromRoot = $parsed;
        }
        try {
            $map = $tree->getDocNamespaces($recursive, $fromRoot);
        } catch (\Throwable) {
            return null;
        }
        if (!\is_array($map)) {
            return null;
        }

        return self::boxHostStringMap($context, $map);
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
        // Baked __phpc_sxe_text lives on TYPE_OBJECT SXE only. Opaque TYPE_VALUE locals are
        // often plain strings — emitting readObject there segfaults concat/echo (#28625).
        if (JITVariable::TYPE_OBJECT !== $args[0]->type) {
            return null;
        }

        return self::readBakedStringProp($context, $args[0], self::BAKED_TEXT_PROP);
    }

    /**
     * Fold string cast of a known SXE without a class hint (#26863).
     * Exact host-tree match, else baked text on a TYPE_OBJECT SXE (#27535).
     * Must not treat opaque TYPE_VALUE (e.g. `$k = "a"`) as SXE (#28625).
     * Must not read SXE baked slots on unrelated TYPE_OBJECT (plain `__toString`
     * classes) — that GEPs past the object header and segfaults under AOT (#28646).
     */
    public static function tryFoldStringCast(
        Context $context,
        JITVariable $var,
        ?string $classHint = null
    ): ?JITVariable {
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
        if (JITVariable::TYPE_OBJECT !== $var->type) {
            return null;
        }
        // Known non-SXE class → leave cast to MagicMethod / __toString (#28646).
        if (!self::classHintMayBeSimpleXmlElement($context, $classHint)) {
            return null;
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
     * Whether a compile-time class hint may refer to SimpleXMLElement (or subclass).
     * Null / object / unknown keep the legacy baked path for untyped SXE temps.
     */
    private static function classHintMayBeSimpleXmlElement(Context $context, ?string $classHint): bool
    {
        if (null === $classHint || '' === $classHint) {
            return true;
        }
        $lc = strtolower(ltrim($classHint, '\\'));
        if ('object' === $lc || 'unknown' === $lc) {
            return true;
        }
        if ('simplexmlelement' === $lc || 'simplemxml_element' === $lc) {
            return true;
        }
        $object = $context->type->object;
        try {
            if ($object->classIsSubclassOf($lc, 'simplexmlelement')) {
                return true;
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    /**
     * Host tree for thin-AOT foreach snapshot (#27535).
     * Exact/token match only — lastTree fallback picked the wrong node after a prior
     * children() foreach (last bound child), so attributes() snapshot became empty (#34543).
     */
    public static function hostTreeForForeach(JITVariable $var): ?\SimpleXMLElement
    {
        if (!UserScriptAotEnv::isActive() || !\extension_loaded('simplexml')) {
            return null;
        }

        return self::lookupExact($var);
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

    /** SimpleXMLElement::offsetGet — host dim/attr (#26863, #27438). */
    public static function tryOffsetGet(Context $context, JITVariable ...$args): ?Value
    {
        if (\count($args) < 2 || !\extension_loaded('simplexml')) {
            return null;
        }
        // XPath node-sets are arrays. Never dim-fold them as the last host element (#27413).
        $token = $args[0]->compileTimeString;
        if (null !== $token && isset(self::$xpathListsByToken[$token])) {
            return null;
        }
        // Only skip real array shapes — TYPE_VALUE is how load_string / child views are
        // boxed under thin AOT; treating it as array-shaped made `$sxe['attr']` miss and
        // fall through to a null/segfault path (#27438). Hashtable/native-array still
        // refuse (xpath packed lists use the token check above).
        if (JITVariable::TYPE_HASHTABLE === $args[0]->type
            || 0 !== ($args[0]->type & JITVariable::IS_NATIVE_ARRAY)
        ) {
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

    /**
     * FETCH_DIM_W lvalue for a compile-time SXE tree (#35810 leftover of #26863).
     *
     * Thin AOT boxes SimpleXMLElement as TYPE_VALUE; hashtable dim-write SIGSEGVs.
     * Mark an ArrayAccess-writable slot so ASSIGN host-folds via {@see tryOffsetSet}.
     */
    public static function tryPrepareDimWrite(
        Context $context,
        JITVariable $container,
        JITVariable $dim
    ): ?JITVariable {
        if (!UserScriptAotEnv::isActive() || !\extension_loaded('simplexml')) {
            return null;
        }
        $token = $container->compileTimeString;
        if (null !== $token && isset(self::$xpathListsByToken[$token])) {
            return null;
        }
        if (JITVariable::TYPE_HASHTABLE === $container->type
            || 0 !== ($container->type & JITVariable::IS_NATIVE_ARRAY)
        ) {
            return null;
        }
        if (null === self::lookup($container)) {
            return null;
        }
        $slot = JitValueBox::alloc($context);
        $var = new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $slot);
        $var->writableArrayAccessReceiver = $container;
        $var->writableArrayAccessKey = $dim;
        $var->isArrayAccessWritableOffset = true;

        return $var;
    }

    /**
     * SimpleXMLElement::offsetSet — host sxe_prop_dim_write (#35810 / php-src sxe.c).
     *
     * Mutates the compile-time tree so a later asXML()/offsetGet fold sees the write.
     *
     * @param JITVariable $args receiver, dim, value
     */
    public static function tryOffsetSet(Context $context, JITVariable ...$args): ?Value
    {
        if (\count($args) < 3 || !\extension_loaded('simplexml')) {
            return null;
        }
        $tree = self::lookup($args[0]);
        if (null === $tree) {
            return null;
        }
        $dim = self::compileTimeDim($context, $args[1]);
        if (null === $dim) {
            throw new \LogicException(
                'SimpleXMLElement::offsetSet() user-script AOT requires a compile-time offset (#35810)'
            );
        }
        if ('' === $dim) {
            // php-src sxe_prop_dim_write — empty attribute name.
            throw new \ValueError('Cannot create attribute with an empty name');
        }
        $value = self::compileTimeOffsetSetValue($context, $args[2]);
        if (null === $value) {
            throw new \LogicException(
                'SimpleXMLElement::offsetSet() user-script AOT requires a compile-time value (#35810)'
            );
        }
        try {
            $tree[$dim] = $value;
        } catch (\ValueError $e) {
            throw $e;
        } catch (\Throwable) {
            return null;
        }

        return self::nullValue($context);
    }

    /**
     * SimpleXMLElement::offsetUnset — host sxe_prop_dim_delete (#35817 leftover of #35810 / php-src sxe.c).
     *
     * Thin AOT boxes SXE as TYPE_VALUE; the generic unset-dim diamond emits a terminator
     * mid-block (`unset_dim_vb_object`) and module verify fails. Mutate the compile-time
     * tree so a later asXML()/isset fold sees the delete.
     *
     * @param JITVariable $args receiver, dim
     */
    public static function tryOffsetUnset(Context $context, JITVariable ...$args): ?Value
    {
        if (!UserScriptAotEnv::isActive() || \count($args) < 2 || !\extension_loaded('simplexml')) {
            return null;
        }
        $token = $args[0]->compileTimeString;
        if (null !== $token && isset(self::$xpathListsByToken[$token])) {
            return null;
        }
        if (JITVariable::TYPE_HASHTABLE === $args[0]->type
            || 0 !== ($args[0]->type & JITVariable::IS_NATIVE_ARRAY)
        ) {
            return null;
        }
        $tree = self::lookupExact($args[0]);
        if (null === $tree) {
            return null;
        }
        $dim = self::compileTimeDim($context, $args[1]);
        if (null === $dim) {
            throw new \LogicException(
                'SimpleXMLElement::offsetUnset() user-script AOT requires a compile-time offset (#35817 leftover of #35810)'
            );
        }
        if ('' === $dim) {
            // php-src sxe_prop_dim_delete — empty attribute name is a no-op.
            return self::nullValue($context);
        }
        try {
            unset($tree[$dim]);
        } catch (\Throwable) {
            return null;
        }

        return self::nullValue($context);
    }

    /**
     * isset($sxe->prop) — host property existence (php-src sxe.c / __isset; #35814).
     *
     * Thin AOT boxes SXE as TYPE_VALUE so value-box propertyIsSet looks at declared slots
     * (none for child elements) and always returns false; fold when the host tree + name
     * are known (peer tryFoldDimIsset #34555 / tryGet #26863).
     */
    public static function tryFoldPropIsset(Context $context, JITVariable $container, string $propName): ?Value
    {
        if (!UserScriptAotEnv::isActive() || !\extension_loaded('simplexml') || '' === $propName) {
            return null;
        }
        $tree = self::lookup($container);
        if (null === $tree) {
            return null;
        }
        try {
            $exists = isset($tree->{$propName});
        } catch (\Throwable) {
            return null;
        }

        return $context->getTypeFromString('int1')->constInt($exists ? 1 : 0, false);
    }

    /**
     * unset($sxe->prop) — host property unset (php-src sxe.c / __unset; #35814).
     *
     * Mutates the compile-time tree so a later asXML() fold omits the child (peer tryOffsetSet).
     *
     * @return bool true when the unset was folded (including no-op on missing props)
     */
    public static function tryPropUnset(Context $context, JITVariable $container, string $propName): bool
    {
        if (!UserScriptAotEnv::isActive() || !\extension_loaded('simplexml') || '' === $propName) {
            return false;
        }
        $tree = self::lookup($container);
        if (null === $tree) {
            return false;
        }
        try {
            unset($tree->{$propName});
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * $sxe->prop = $v — host sxe_property_write (#35820 leftover of #35814 / #20539).
     *
     * Thin AOT otherwise propertyStore's a TYPE_VALUE box (SIGABRT). Mutate the
     * compile-time tree so a later asXML() fold sees the write (peer tryOffsetSet).
     */
    public static function tryPropSet(
        Context $context,
        JITVariable $container,
        string $propName,
        JITVariable $value
    ): ?Value {
        if (!UserScriptAotEnv::isActive() || !\extension_loaded('simplexml') || '' === $propName) {
            return null;
        }
        $tree = self::lookup($container);
        if (null === $tree) {
            return null;
        }
        $lit = self::compileTimeOffsetSetValue($context, $value);
        if (null === $lit) {
            throw new \LogicException(
                'SimpleXMLElement property write user-script AOT requires a compile-time value (#35820)'
            );
        }
        try {
            $tree->{$propName} = $lit;
        } catch (\Throwable) {
            return null;
        }

        return self::nullValue($context);
    }

    /**
     * isset($sxe[$dim]) — host has_dimension (php-src sxe_object_has_dimension; #34555).
     *
     * Thin AOT boxes SXE as TYPE_VALUE so ArrayAccess isset is skipped and HT probe
     * always returns false; fold when the host tree + dim are known (peer tryOffsetGet).
     * Runtime dims (foreach $k) use baked `__phpc_sxe_dims`.
     */
    public static function tryFoldDimIsset(Context $context, JITVariable $container, JITVariable $dim): ?Value
    {
        $xpathIsset = self::tryFoldXpathListDimIsset($context, $container, $dim);
        if (null !== $xpathIsset) {
            return $xpathIsset;
        }
        $exists = self::hostDimExists($container, $dim, $context);
        if (null !== $exists) {
            return $context->getTypeFromString('int1')->constInt($exists ? 1 : 0, false);
        }

        return self::tryCompileRuntimeDimIsset($context, $container, $dim);
    }

    /**
     * empty($sxe[$dim]) — Zend empty uses has_dimension then value emptiness (#34555).
     */
    public static function tryFoldDimEmpty(Context $context, JITVariable $container, JITVariable $dim): ?Value
    {
        $empty = self::hostDimEmpty($container, $dim, $context);
        if (null !== $empty) {
            return $context->getTypeFromString('int1')->constInt($empty ? 1 : 0, false);
        }

        return self::tryCompileRuntimeDimEmpty($context, $container, $dim);
    }

    /**
     * Runtime isset via baked dim HT when the host tree is known but $dim is not constant.
     */
    public static function tryCompileRuntimeDimIsset(
        Context $context,
        JITVariable $container,
        JITVariable $dim
    ): ?Value {
        $htVar = self::bakedDimsHashtableVar($context, $container);
        if (null === $htVar) {
            return null;
        }

        return HashTableHelper::offsetIsSetDim(
            $context,
            HashTableHelper::loadHashtablePointer($context, $htVar),
            $dim
        );
    }

    /**
     * Runtime empty via baked dim HT (missing key ⇒ empty; else stored empty flag).
     *
     * Note: empty($sxe[0]) can be false even when (string)$sxe[0] === '' (element with
     * children) — bake host empty() as int1, do not use string emptiness (#34555).
     */
    public static function tryCompileRuntimeDimEmpty(
        Context $context,
        JITVariable $container,
        JITVariable $dim
    ): ?Value {
        $htVar = self::bakedDimsHashtableVar($context, $container);
        if (null === $htVar) {
            return null;
        }
        $ht = HashTableHelper::loadHashtablePointer($context, $htVar);
        $isset = HashTableHelper::offsetIsSetDim($context, $ht, $dim);
        $tag = 'sxe_empty_'.(string) spl_object_id($context).'_'.(string) spl_object_id($dim);
        $missingBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, $tag.'_missing');
        $presentBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, $tag.'_present');
        $doneBlock = \PHPCompiler\JIT\BasicBlockHelper::append($context, $tag.'_done');
        $i1 = $context->getTypeFromString('int1');

        $context->builder->branchIf($isset, $presentBlock, $missingBlock);

        $context->builder->positionAtEnd($missingBlock);
        $missingEmpty = $i1->constInt(1, false);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($presentBlock);
        // Values are int64 flags: 1 = empty, 0 = non-empty (host empty() at bake time).
        $fetched = $htVar->dimFetch($dim);
        $flag = $context->helper->loadValue($fetched);
        $i64 = $context->getTypeFromString('int64');
        if (JITVariable::TYPE_NATIVE_LONG !== $fetched->type) {
            // dimFetch may box — compare via boolval/intval path
            $flagLong = (new \PHPCompiler\ext\standard\intval())->call($context, $fetched);
            $valueEmpty = $context->builder->icmp(
                \PHPLLVM\Builder::INT_NE,
                $flagLong,
                $i64->constInt(0, false)
            );
        } else {
            $valueEmpty = $context->builder->icmp(
                \PHPLLVM\Builder::INT_NE,
                $flag,
                $i64->constInt(0, false)
            );
        }
        $presentEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($missingEmpty, $missingBlock);
        $phi->addIncoming($valueEmpty, $presentEnd);

        return $phi;
    }

    /** @return JITVariable|null TYPE_HASHTABLE view of baked dims */
    private static function bakedDimsHashtableVar(Context $context, JITVariable $container): ?JITVariable
    {
        if (!\extension_loaded('simplexml')) {
            return null;
        }
        // Only when this Variable was materialized from a host tree (attrs were baked).
        if (null === self::lookup($container)) {
            return null;
        }
        if (JITVariable::TYPE_HASHTABLE === $container->type
            || 0 !== ($container->type & JITVariable::IS_NATIVE_ARRAY)
        ) {
            return null;
        }
        $obj = self::objectPtrForBakedProps($context, $container);
        if (null === $obj) {
            return null;
        }
        try {
            $slot = $context->type->object->propertySlotFor($obj, 'SimpleXMLElement', self::BAKED_DIMS_PROP);
        } catch (\Throwable) {
            return null;
        }
        $raw = $context->builder->load($slot);
        $htPtr = $context->builder->pointerCast($raw, $context->getTypeFromString('__hashtable__*'));

        return new JITVariable(
            $context,
            JITVariable::TYPE_HASHTABLE,
            JITVariable::KIND_VALUE,
            $htPtr
        );
    }

    private static function objectPtrForBakedProps(Context $context, JITVariable $container): ?Value
    {
        if (JITVariable::TYPE_OBJECT === $container->type) {
            return JITVariable::KIND_VALUE === $container->kind
                ? $container->value
                : $context->builder->load($container->value);
        }
        if (JITVariable::TYPE_VALUE !== $container->type) {
            return null;
        }
        try {
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $container);

            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                $valuePtr
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * isset($xpathResult[$i]) — bounds check on packed xpath node-set (#27534 / #26911).
     *
     * hostDimExists deliberately skips xpath lists; without this fold, tryCompileRuntimeDimIsset
     * probes SXE baked-dim slots on a TYPE_VALUE array and segfaults under AOT.
     */
    private static function tryFoldXpathListDimIsset(
        Context $context,
        JITVariable $container,
        JITVariable $dim
    ): ?Value {
        $token = $container->compileTimeString;
        if (null === $token || !isset(self::$xpathListsByToken[$token])) {
            return null;
        }
        $idx = self::compileTimeDim($context, $dim);
        if (!\is_int($idx)) {
            return null;
        }
        $list = self::$xpathListsByToken[$token];
        $exists = $idx >= 0 && $idx < \count($list);

        return $context->getTypeFromString('int1')->constInt($exists ? 1 : 0, false);
    }

    /** @return bool|null null when not foldable */
    private static function hostDimExists(JITVariable $container, JITVariable $dim, Context $context): ?bool
    {
        // Match tryOffsetGet: no UserScriptAotEnv gate — trees are live during dim lowering (#34555).
        if (!\extension_loaded('simplexml')) {
            return null;
        }
        $token = $container->compileTimeString;
        if (null !== $token && isset(self::$xpathListsByToken[$token])) {
            return null;
        }
        if (JITVariable::TYPE_HASHTABLE === $container->type
            || 0 !== ($container->type & JITVariable::IS_NATIVE_ARRAY)
        ) {
            return null;
        }
        $tree = self::lookup($container);
        if (null === $tree) {
            return null;
        }
        $key = self::compileTimeDim($context, $dim);
        if (null === $key) {
            return null;
        }
        try {
            return isset($tree[$key]);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return bool|null null when not foldable */
    private static function hostDimEmpty(JITVariable $container, JITVariable $dim, Context $context): ?bool
    {
        if (!\extension_loaded('simplexml')) {
            return null;
        }
        $token = $container->compileTimeString;
        if (null !== $token && isset(self::$xpathListsByToken[$token])) {
            return null;
        }
        if (JITVariable::TYPE_HASHTABLE === $container->type
            || 0 !== ($container->type & JITVariable::IS_NATIVE_ARRAY)
        ) {
            return null;
        }
        $tree = self::lookup($container);
        if (null === $tree) {
            return null;
        }
        $key = self::compileTimeDim($context, $dim);
        if (null === $key) {
            return null;
        }
        try {
            return empty($tree[$key]);
        } catch (\Throwable) {
            return null;
        }
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
     * SimpleXMLElement::hasChildren — host RecursiveIterator fold (#35827 leftover of #26863).
     * php-src: ext/simplexml/sxe.c — PHP_METHOD(SimpleXMLElement, hasChildren)
     *
     * Fresh trees have UNDEF iter.data → false (php-src RETURN_FALSE before rewind).
     * Exact host match only — lastTree would mis-fold foreach/child views.
     */
    public static function tryHasChildren(Context $context, JITVariable ...$args): ?Value
    {
        if ([] === $args || !\extension_loaded('simplexml')) {
            return null;
        }
        $tree = self::lookupExact($args[0]);
        if (null === $tree) {
            return null;
        }
        try {
            $has = $tree->hasChildren();
        } catch (\Throwable) {
            return null;
        }
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt($has ? 1 : 0, false)
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
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
        // Soft-null $qualifiedName — E_DEPRECATED then empty → ValueError (#31554 / sxe.c).
        if (JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant) {
            JitStringBuiltinArg::lowerTrimFamilyString(
                $context,
                $args[1],
                'SimpleXMLElement::addChild',
                0,
                'qualifiedName'
            );
            throw new \ValueError(
                'SimpleXMLElement::addChild(): Argument #1 ($qualifiedName) cannot be empty'
            );
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
     * Fold SimpleXMLElement::addAttribute via host php-src (sxe.c zim_simplexmlelement_addAttribute; #35806).
     * Mutates the host tree so a subsequent asXML() fold includes the attribute.
     */
    public static function tryAddAttribute(Context $context, JITVariable ...$args): ?Value
    {
        if (\count($args) < 3 || !\extension_loaded('simplexml')) {
            return null;
        }
        $tree = self::lookup($args[0]);
        if (null === $tree) {
            return null;
        }
        // Soft-null $qualifiedName — E_DEPRECATED then empty → ValueError (#31554 / sxe.c).
        if (JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant) {
            JitStringBuiltinArg::lowerTrimFamilyString(
                $context,
                $args[1],
                'SimpleXMLElement::addAttribute',
                0,
                'qualifiedName'
            );
            throw new \ValueError(
                'SimpleXMLElement::addAttribute(): Argument #1 ($qualifiedName) cannot be empty'
            );
        }
        $name = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $name) {
            return null;
        }
        if ('' === $name) {
            throw new \ValueError(
                'SimpleXMLElement::addAttribute(): Argument #1 ($qualifiedName) cannot be empty'
            );
        }
        if (JITVariable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant) {
            throw new \TypeError('SimpleXMLElement::addAttribute(): Argument #2 ($value) must be of type string');
        }
        $value = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
        if (null === $value) {
            return null;
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
        $warn = null;
        set_error_handler(static function (int $_errno, string $errstr) use (&$warn): bool {
            $warn = $errstr;

            return true;
        });
        try {
            if (null !== $namespace && '' !== $namespace) {
                $tree->addAttribute($name, $value, $namespace);
            } else {
                $tree->addAttribute($name, $value);
            }
        } catch (\ValueError $e) {
            throw $e;
        } catch (\Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }
        if (is_string($warn) && '' !== $warn) {
            JitBuiltinWarning::emit($context, $warn);
        }

        return self::nullValue($context);
    }

    /**
     * Compile-time SimpleXMLElement::registerXPathNamespace via host php-src (#27534 / #31656).
     * Mutates the host tree so a subsequent literal xpath() fold sees registered prefixes.
     * Soft-null prefix/namespace — E_DEPRECATED then empty-prefix → false (sxe.c).
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
        // Soft-null $prefix — E_DEPRECATED then empty → false (#31656 / sxe.c).
        if (JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant) {
            JitStringBuiltinArg::lowerTrimFamilyString(
                $context,
                $args[1],
                'SimpleXMLElement::registerXPathNamespace',
                0,
                'prefix'
            );
            $prefix = '';
        } else {
            $prefix = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            if (null === $prefix) {
                return null;
            }
        }
        // Soft-null $namespace — E_DEPRECATED then register empty URI (#31656 / sxe.c).
        if (JITVariable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant) {
            JitStringBuiltinArg::lowerTrimFamilyString(
                $context,
                $args[2],
                'SimpleXMLElement::registerXPathNamespace',
                1,
                'namespace'
            );
            $namespace = '';
        } else {
            $namespace = JitStringBuiltinArg::compileTimeLiteral($args[2]) ?? $args[2]->compileTimeString;
            if (null === $namespace) {
                return null;
            }
        }
        $ok = false;
        if ('' !== $prefix) {
            try {
                $ok = (bool) $tree->registerXPathNamespace($prefix, $namespace);
            } catch (\Throwable) {
                return null;
            }
        }
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt($ok ? 1 : 0, false)
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
        // Soft-null $expression — E_DEPRECATED then empty → Invalid expression + false (#31530 / sxe.c).
        if (JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant) {
            JitStringBuiltinArg::lowerTrimFamilyString(
                $context,
                $args[1],
                'SimpleXMLElement::xpath',
                0,
                'expression'
            );
            $path = '';
        } else {
            $path = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            if (null === $path) {
                return null;
            }
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
            // Bake name/text like materializeElement / foreach snapshots (#27535).
            // Without this, `$xpath[$i]->getName()` reads an empty baked slot under AOT (#34539).
            self::store($receiver, $node);
            self::bakeElementScalars($context, $receiver, $node);
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
        $xmlnsInject = self::xmlnsAttrForIsolation($tree, $xml);
        if ('' !== $xmlnsInject) {
            $xml = preg_replace('/^(<\s*[^\s>]+)/', '$1'.$xmlnsInject, $xml, 1) ?? $xml;
        }
        $copy = \simplexml_load_string($xml);
        if ($copy instanceof \SimpleXMLElement) {
            return $copy;
        }

        return clone $tree;
    }

    /**
     * Re-parse fragments lose ancestor xmlns — inject from getNamespaces(true) (#22738 / #27535).
     */
    private static function xmlnsAttrForIsolation(\SimpleXMLElement $tree, string $xml): string
    {
        if (!preg_match('/^<([a-zA-Z_][\w:.-]*)((?:\s[^>]*)?)/', ltrim($xml), $m)) {
            return '';
        }
        $openAttrs = $m[2] ?? '';
        if (str_contains($openAttrs, 'xmlns')) {
            return '';
        }
        $decls = [];
        foreach ($tree->getNamespaces(true) as $prefix => $uri) {
            $escaped = htmlspecialchars((string) $uri, ENT_QUOTES | ENT_XML1);
            if ('' === $prefix) {
                $decls[] = 'xmlns="'.$escaped.'"';
            } else {
                $decls[] = 'xmlns:'.$prefix.'="'.$escaped.'"';
            }
        }

        return [] === $decls ? '' : ' '.implode(' ', $decls);
    }

    private static function bakeElementScalars(Context $context, JITVariable $receiver, \SimpleXMLElement $tree): void
    {
        $obj = JITVariable::KIND_VALUE === $receiver->kind
            ? $receiver->value
            : $context->builder->load($receiver->value);
        self::storeBakedStringProp($context, $obj, self::BAKED_NAME_PROP, $tree->getName());
        self::storeBakedStringProp($context, $obj, self::BAKED_TEXT_PROP, (string) $tree);
        self::storeBakedDimsMap($context, $obj, $tree);
    }

    /**
     * Bake attribute + numeric dims into one HT for runtime isset/empty (#34555).
     * php-src sxe_object_has_dimension — string keys are attrs; int keys are element offsets.
     * Values are int64 empty-flags from host empty() (not string cast — elements with
     * children can be non-empty while (string) is '').
     */
    private static function storeBakedDimsMap(Context $context, Value $obj, \SimpleXMLElement $tree): void
    {
        $ht = HashTableHelper::alloc($context);
        $setStringKeyLong = $context->lookupFunction('__hashtable__setStringKeyLong');
        $i64 = $context->getTypeFromString('int64');
        $attrs = $tree->attributes();
        if ($attrs instanceof \SimpleXMLElement || $attrs instanceof \Traversable) {
            foreach ($attrs as $name => $attr) {
                $key = $context->builder->load($context->constantStringFromString((string) $name));
                $emptyFlag = empty($tree[(string) $name]) ? 1 : 0;
                $context->builder->call(
                    $setStringKeyLong,
                    $ht,
                    $key,
                    $i64->constInt($emptyFlag, false)
                );
            }
        }
        for ($i = 0; isset($tree[$i]); ++$i) {
            $emptyFlag = empty($tree[$i]) ? 1 : 0;
            $flagVar = new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $i64->constInt($emptyFlag, false)
            );
            HashTableHelper::setAtIndex(
                $context,
                $ht,
                $i64->constInt($i, false),
                $flagVar
            );
        }
        $dimsVar = new JITVariable(
            $context,
            JITVariable::TYPE_HASHTABLE,
            JITVariable::KIND_VALUE,
            $ht
        );
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($obj, 'SimpleXMLElement', self::BAKED_DIMS_PROP),
            $dimsVar,
            JITVariable::TYPE_HASHTABLE
        );
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
        // Only TYPE_OBJECT SXE carries baked slots. TYPE_VALUE must not call readObject —
        // that path was taken for plain string locals and segfaulted under AOT (#28625).
        if (JITVariable::TYPE_OBJECT !== $receiver->type) {
            return null;
        }
        try {
            $obj = JITVariable::KIND_VALUE === $receiver->kind
                ? $receiver->value
                : $context->builder->load($receiver->value);

            return self::readBakedStringPropFromObjectPtr($context, $obj, $prop);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Read a baked SXE string slot from an `__object__*` (foreach value-box unwrap; #34543).
     */
    public static function readBakedTextFromObjectPtr(Context $context, Value $obj): Value
    {
        $owned = self::loadBakedOwnedStringFromObjectPtr($context, $obj, self::BAKED_TEXT_PROP);

        return $owned;
    }

    private static function readBakedStringPropFromObjectPtr(Context $context, Value $obj, string $prop): Value
    {
        $owned = self::loadBakedOwnedStringFromObjectPtr($context, $obj, $prop);

        return self::boxOwnedString($context, $owned);
    }

    private static function loadBakedOwnedStringFromObjectPtr(Context $context, Value $obj, string $prop): Value
    {
        $slot = $context->type->object->propertySlotFor($obj, 'SimpleXMLElement', $prop);
        $raw = $context->builder->load($slot);
        $strPtr = $context->builder->pointerCast($raw, $context->getTypeFromString('__string__*'));

        return $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $strPtr
        );
    }

    /**
     * Whether string cast of a TYPE_VALUE box may be an SXE foreach element (#34543).
     * Null/object/unknown hints stay eligible; unrelated class hints refuse (#28646).
     */
    public static function valueBoxMayBeSimpleXmlElement(Context $context, ?string $classHint): bool
    {
        return self::classHintMayBeSimpleXmlElement($context, $classHint);
    }

    /** SimpleXMLElement class id for runtime value-box probes (#34543). */
    public static function simpleXmlElementClassId(Context $context): int
    {
        return $context->type->object->lookup('SimpleXMLElement');
    }

    /**
     * @param array<string|int, string> $map prefix ('' = default) => URI
     */
    private static function boxHostStringMap(Context $context, array $map): Value
    {
        $ht = new \PHPCompiler\VM\HashTable();
        foreach ($map as $key => $value) {
            $var = new \PHPCompiler\VM\Variable();
            $var->string((string) $value);
            $ht->update((string) $key, $var);
        }

        $htVar = HashTableHelper::variableFromVmHashTable($context, $ht);
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            JitValueBox::pointer($context, $slot),
            $context->helper->loadValue($htVar)
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
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

    /**
     * Host SimpleXMLElement tracked for user-script AOT (dom_import_simplexml fold; #34413).
     */
    public static function compileTimeTree(JITVariable $receiver): ?\SimpleXMLElement
    {
        if (!UserScriptAotEnv::isActive()) {
            return null;
        }

        return self::lookup($receiver);
    }

    /**
     * Compile-time token for a tracked SimpleXMLElement receiver (#20137 dom_import_simplexml).
     */
    public static function compileTimeToken(JITVariable $receiver): ?string
    {
        return self::ensureCompileTimeToken($receiver, self::compileTimeTree($receiver));
    }

    /**
     * Resolve or register a host-tree token when the tree is known (#20137).
     */
    public static function ensureCompileTimeToken(JITVariable $receiver, ?\SimpleXMLElement $tree): ?string
    {
        if (!UserScriptAotEnv::isActive() || !($tree instanceof \SimpleXMLElement)) {
            return null;
        }
        $existing = self::compileTimeTokenFromReceiverOrTree($receiver, $tree);
        if (null !== $existing) {
            return $existing;
        }
        if (null === self::$trees) {
            self::$trees = new \SplObjectStorage();
        }
        self::$trees[$receiver] = $tree;
        $token = '__phpc_sxml_'.(++self::$tokenSeq);
        $receiver->compileTimeString = $token;
        self::$treesByToken[$token] = $tree;
        self::$lastTree = $tree;

        return $token;
    }

    private static function compileTimeTokenFromReceiverOrTree(
        JITVariable $receiver,
        \SimpleXMLElement $tree
    ): ?string {
        if (null !== $receiver->compileTimeString
            && isset(self::$treesByToken[$receiver->compileTimeString])
        ) {
            return $receiver->compileTimeString;
        }
        if (null !== self::$trees && isset(self::$trees[$receiver])) {
            foreach (self::$treesByToken as $token => $candidate) {
                if ($candidate === self::$trees[$receiver]) {
                    return $token;
                }
            }
        }
        foreach (self::$treesByToken as $token => $candidate) {
            if ($candidate === $tree) {
                return $token;
            }
        }

        return null;
    }

    /**
     * dom_import_simplexml live-sharing — mutate the tracked host tree at compile time (#20137).
     */
    public static function syncHostTreeTextContent(Context $context, string $token, string $text): void
    {
        if (!UserScriptAotEnv::isActive() || !\extension_loaded('simplexml')) {
            return;
        }
        $tree = self::$treesByToken[$token] ?? null;
        if (!$tree instanceof \SimpleXMLElement) {
            return;
        }
        $tree[0] = $text;
    }

    /** @see syncHostTreeTextContent */
    public static function syncHostTreeAttribute(Context $context, string $token, string $name, string $value): void
    {
        if (!UserScriptAotEnv::isActive() || !\extension_loaded('simplexml')) {
            return;
        }
        $tree = self::$treesByToken[$token] ?? null;
        if (!$tree instanceof \SimpleXMLElement) {
            return;
        }
        $tree[$name] = $value;
    }

    /**
     * Array-shaped operands must not fall back to lastTree for count() (#27413).
     * Value-boxed SXE from load/property still count via an exact host-tree token
     * once applyPendingElementAssign has bound it (#26863 / #28639).
     */
    private static function isArrayShapedCountOperand(JITVariable $var): bool
    {
        if (JITVariable::TYPE_HASHTABLE === $var->type) {
            return true;
        }
        if (0 !== ($var->type & JITVariable::IS_NATIVE_ARRAY)) {
            return true;
        }
        if (JITVariable::TYPE_VALUE === $var->type) {
            // Opaque value boxes (e.g. xpath lists without SXE token) stay array-shaped.
            return null === self::lookupExact($var);
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

    private static function compileTimeOffsetSetValue(Context $context, JITVariable $value): ?string
    {
        if (JITVariable::TYPE_NULL === $value->type || $value->isNullConstant) {
            return '';
        }
        $lit = JitStringBuiltinArg::compileTimeLiteral($value) ?? $value->compileTimeString;
        if (null !== $lit) {
            return $lit;
        }
        if (null !== ($value->compileTimeLong ?? null)) {
            return (string) (int) $value->compileTimeLong;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $value->type && JITVariable::KIND_VALUE === $value->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($value->value->value)) {
                return (string) (int) $lib->LLVMConstIntGetSExtValue($value->value->value);
            }
        }

        return null;
    }

    private static function compileTimeBool(Context $context, JITVariable $var): ?bool
    {
        if (null !== $var->compileTimeLong) {
            return 0 !== (int) $var->compileTimeLong;
        }
        if (null !== $var->compileTimeConstantName) {
            $cn = strtolower($var->compileTimeConstantName);
            if ('true' === $cn) {
                return true;
            }
            if ('false' === $cn) {
                return false;
            }
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $var->type && JITVariable::KIND_VALUE === $var->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($var->value->value)) {
                return 0 !== (int) $lib->LLVMConstIntGetSExtValue($var->value->value);
            }
        }

        return null;
    }

    /**
     * Bake php_libxml_error_handler's three-line warning surface into user-script AOT (#31183).
     *
     * @param list<\LibXMLError> $hostErrors
     */
    private static function emitLoadStringParserWarnings(Context $context, string $source, array $hostErrors): void
    {
        $snippet = trim($source);
        $prefix = 'simplexml_load_string(): ';
        if ([] === $hostErrors) {
            JitBuiltinWarning::emit(
                $context,
                $prefix.'Entity: line 1: parser error : StartTag: invalid element name'
            );
            JitBuiltinWarning::emit($context, $prefix.$snippet);
            JitBuiltinWarning::emit($context, $prefix.str_repeat(' ', max(0, \strlen($snippet) > 0 ? 1 : 0)).'^');

            return;
        }

        foreach ($hostErrors as $err) {
            $line = (int) $err->line;
            $message = rtrim((string) $err->message);
            $column = max(0, (int) $err->column - 1);
            JitBuiltinWarning::emit(
                $context,
                $prefix.'Entity: line '.$line.': parser error : '.$message
            );
            JitBuiltinWarning::emit($context, $prefix.$snippet);
            JitBuiltinWarning::emit($context, $prefix.str_repeat(' ', $column).'^');
        }
    }

    /**
     * File-load parser warnings use "path:line" locus (php-src; #31183 sibling / #34454).
     *
     * @param list<\LibXMLError> $hostErrors
     */
    private static function emitLoadFileParserWarnings(Context $context, string $path, array $hostErrors): void
    {
        $prefix = 'simplexml_load_file(): ';
        $contents = @file_get_contents($path);
        $snippet = is_string($contents) ? trim($contents) : '';
        if ([] === $hostErrors) {
            JitBuiltinWarning::emit(
                $context,
                $prefix.$path.':1: parser error : StartTag: invalid element name'
            );
            JitBuiltinWarning::emit($context, $prefix.$snippet);
            JitBuiltinWarning::emit($context, $prefix.str_repeat(' ', max(0, \strlen($snippet) > 0 ? 1 : 0)).'^');

            return;
        }

        foreach ($hostErrors as $err) {
            $line = (int) $err->line;
            $message = rtrim((string) $err->message);
            $column = max(0, (int) $err->column - 1);
            JitBuiltinWarning::emit(
                $context,
                $prefix.$path.':'.$line.': parser error : '.$message
            );
            JitBuiltinWarning::emit($context, $prefix.$snippet);
            JitBuiltinWarning::emit($context, $prefix.str_repeat(' ', $column).'^');
        }
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
