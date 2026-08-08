<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal as FuncInternal;
use PHPCompiler\Func\PHP as PhpFunc;
use PHPCompiler\MethodVisibility;
use PHPCompiler\VM\BackedEnum;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\ext\curl\CurlFileSerializeDeny;
use PHPCompiler\ext\dba\DbaSerializeDeny;
use PHPCompiler\ext\dom\DomXPathSerializeDeny;
use PHPCompiler\ext\enchant\EnchantSerializeDeny;
use PHPCompiler\ext\fileinfo\FinfoSerializeDeny;
use PHPCompiler\ext\ffi\FfiSerializeDeny;
use PHPCompiler\ext\ftp\FtpSerializeDeny;
use PHPCompiler\ext\intl\IntlSerializeDeny;
use PHPCompiler\ext\ldap\LdapSerializeDeny;
use PHPCompiler\ext\openssl\OpensslSerializeDeny;
use PHPCompiler\ext\pdo\PdoSerializeDeny;
use PHPCompiler\ext\phar\PharSerializeDeny;
use PHPCompiler\ext\pgsql\PgsqlSerializeDeny;
use PHPCompiler\ext\random\RandomSecureSerializeDeny;
use PHPCompiler\ext\reflection\ReflectionSerializeDeny;
use PHPCompiler\ext\simplexml\SimpleXmlSerializeDeny;
use PHPCompiler\ext\sqlite3\Sqlite3SerializeDeny;
use PHPCompiler\ext\sockets\SocketSerializeDeny;
use PHPCompiler\ext\sysvshm\SysvIpcSerializeDeny;
use PHPCompiler\ext\spl\SplArraySerializeSupport;
use PHPCompiler\ext\spl\SplDllistSerializeSupport;
use PHPCompiler\ext\spl\InternalIteratorSerializeDeny;
use PHPCompiler\ext\spl\SplFileIteratorSerializeDeny;
use PHPCompiler\ext\spl\SplFixedArraySerializeSupport;
use PHPCompiler\ext\spl\SplObjectStorageSerializeSupport;
use PHPCompiler\ext\xml\XmlParserSerializeDeny;
use PHPCompiler\VM\DateIntervalSupport;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\LazyObjectSupport;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\TypedPropertyCheck;
use PHPCompiler\VM\Variable;

/**
 * serialize() / unserialize() hooks: __serialize/__unserialize (#1365),
 * legacy __sleep/__wakeup and Serializable (#3287).
 *
 * php-src: ext/standard/var.c, ext/standard/var_unserializer.c
 */
final class VmSerialize
{
    private const CLASS_INCOMPLETE = '__php_incomplete_class';
    private const INCOMPLETE_CLASS_NAME_PROP = '__PHP_Incomplete_Class_Name';

    public static function serializeValue(Context $ctx, Variable $value, ?Frame $frame = null): string
    {
        $ownedState = false;
        $state = $ctx->activeSerializeRefState;
        if (null === $state) {
            $state = new VmSerializeRefState();
            $ctx->activeSerializeRefState = $state;
            $ownedState = true;
        }
        try {
            return self::serializeValueWithState($ctx, $value, $state, $frame);
        } finally {
            if ($ownedState) {
                $ctx->activeSerializeRefState = null;
            }
        }
    }

    private static function serializeValueWithState(
        Context $ctx,
        Variable $value,
        VmSerializeRefState $state,
        ?Frame $frame = null
    ): string {
        $value = $value->resolveIndirect();
        $resourceWire = self::serializeResourceWire($value);
        if (null !== $resourceWire) {
            return $resourceWire;
        }
        $enumRef = self::enumCaseRefFromVariable($value);
        if (null !== $enumRef) {
            return self::encodeEnumCaseLiteral($enumRef->className, $enumRef->caseName);
        }
        if (Variable::TYPE_OBJECT === $value->type) {
            return self::encodeWireObject($ctx, $value->toObject(), $state, $frame);
        }

        if (Variable::TYPE_ARRAY === $value->type) {
            // php_add_var_hash: arrays bump n but are not stored for R: lookup (var.c).
            $state->bumpIndex();

            return self::encodeWireArray($ctx, $value, $state, $frame);
        }

        return self::serializeExported(self::exportForSerialize($ctx, $value));
    }

    /** Zend enum wire format: E:len:"EnumName:CaseName"; (php-src ext/standard/var.c). */
    public static function encodeEnumCaseLiteral(string $className, string $caseName): string
    {
        $payload = $className.':'.$caseName;
        $len = \strlen($payload);

        return 'E:'.$len.':"'.$payload.'";';
    }

    /**
     * Serialize exported PHP data using VM serialize_precision (php-src var.c / PG(serialize_precision); #7100, #7103).
     */
    public static function serializeExported(mixed $exported): string
    {
        return VmSerializeFormat::encodeExported($exported);
    }

    /**
     * @param array<string, mixed>|null $options unserialize() options (allowed_classes, max_depth; #3300)
     */
    public static function unserializePayload(Context $ctx, string $payload, ?array $options = null, ?Frame $frame = null): mixed
    {
        if (str_starts_with($payload, 'C:')) {
            $parsed = self::parseSerializableObjectPayload($payload);
            if (null === $parsed) {
                return false;
            }
            [$className, $data] = $parsed;
            if (!self::isClassAllowedForUnserialize($className, $options)) {
                return self::instantiateIncompleteObject($ctx, $className, []);
            }
            $class = self::resolveClassEntryForUnserialize($ctx, $className);
            if (null === $class) {
                return false;
            }
            if (!self::implementsLegacySerializable($class)) {
                return false;
            }

            return self::instantiateLegacySerializable($ctx, $class, $data);
        }

        if (str_starts_with($payload, 'E:')) {
            $parsed = self::parseEnumCasePayload($payload);
            if (null === $parsed) {
                return false;
            }
            [$className, $caseName] = $parsed;
            $resolved = self::resolveEnumCaseVariable($ctx, $className, $caseName);
            if (null === $resolved) {
                return false;
            }

            return $resolved;
        }

        if (str_starts_with($payload, 'O:')) {
            $header = self::parseObjectWireHeader($payload);
            if (null === $header) {
                return false;
            }
            [$className] = $header;
            if (0 === strcasecmp($className, 'Closure')) {
                throw new \Exception("Unserialization of 'Closure' is not allowed");
            }
            // php-src Zend/zend_fibers.c — zend_class_unserialize_deny (#23043).
            if (0 === strcasecmp($className, 'Fiber')) {
                throw new \Exception("Unserialization of 'Fiber' is not allowed");
            }
            // php-src Zend/zend_generators.c — zend_class_unserialize_deny (#23044).
            if (0 === strcasecmp($className, 'Generator')) {
                throw new \Exception("Unserialization of 'Generator' is not allowed");
            }
            // php-src Zend/zend_weakrefs.c — ZEND_ACC_NOT_SERIALIZABLE (#23062, #23063).
            if (0 === strcasecmp($className, 'WeakMap')) {
                throw new \Exception("Unserialization of 'WeakMap' is not allowed");
            }
            if (0 === strcasecmp($className, 'WeakReference')) {
                throw new \Exception("Unserialization of 'WeakReference' is not allowed");
            }
            // php-src Zend/zend_exceptions.c — @not-serializable SensitiveParameterValue (#23086).
            if (0 === strcasecmp($className, 'SensitiveParameterValue')) {
                throw new \Exception("Unserialization of 'SensitiveParameterValue' is not allowed");
            }
            // php-src ext/curl/curl_file.stub.php — @not-serializable (#23064).
            CurlFileSerializeDeny::rejectUnserialization($className);
            // php-src ext/dom/php_dom.stub.php — @not-serializable DOMXPath / Dom\XPath (#23088).
            DomXPathSerializeDeny::rejectUnserialization($className);
            // php-src ext/fileinfo/fileinfo.stub.php — @not-serializable (#23093).
            FinfoSerializeDeny::rejectUnserialization($className);
            // php-src ext/ftp/ftp.stub.php — @not-serializable (#23134).
            FtpSerializeDeny::rejectUnserialization($className);
            // php-src ext/intl/*.stub.php — @not-serializable (#23092).
            IntlSerializeDeny::rejectUnserialization($className, $ctx);
            // php-src ext/sockets/sockets.stub.php — @not-serializable (#23094).
            SocketSerializeDeny::rejectUnserialization($className);
            // php-src ext/sysvmsg|sysvsem|sysvshm|shmop — @not-serializable (#23132).
            SysvIpcSerializeDeny::rejectUnserialization($className);
            // php-src ext/openssl/openssl.stub.php — @not-serializable (#23100).
            OpensslSerializeDeny::rejectUnserialization($className);
            // php-src ext/pdo/pdo_dbh.stub.php + pdo_stmt.stub.php — @not-serializable (#23103).
            PdoSerializeDeny::rejectUnserialization($className);
            // php-src ext/pgsql/pgsql.stub.php — @not-serializable (#23135).
            PgsqlSerializeDeny::rejectUnserialization($className);
            // php-src ext/ldap/ldap.stub.php — @not-serializable (#23169).
            LdapSerializeDeny::rejectUnserialization($className);
            // php-src ext/zlib/zlib.stub.php — @not-serializable (#23101).
            ZlibContextSerializeDeny::rejectUnserialization($className);
            // php-src ext/random/random.stub.php — @not-serializable Random\Engine\Secure (#23102).
            RandomSecureSerializeDeny::rejectUnserialization($className);
            // php-src ext/xml/xml.stub.php — @not-serializable XMLParser (#23111).
            XmlParserSerializeDeny::rejectUnserialization($className);
            // php-src ext/reflection/php_reflection.stub.php — @not-serializable (#23087).
            ReflectionSerializeDeny::rejectUnserialization($className);
            // php-src Zend/zend_interfaces.stub.php — @not-serializable InternalIterator (#23167).
            InternalIteratorSerializeDeny::rejectUnserialization($className);
            SplFileIteratorSerializeDeny::rejectUnserialization($className);
            // php-src ext/simplexml/sxe.c — zend_class_unserialize_deny (#23072).
            SimpleXmlSerializeDeny::rejectUnserialization($className, $ctx);
            // php-src ext/sqlite3/sqlite3.stub.php — @not-serializable (#23137).
            Sqlite3SerializeDeny::rejectUnserialization($className);
            // php-src ext/dba/dba.stub.php — @not-serializable (#23113).
            DbaSerializeDeny::rejectUnserialization($className);
            // php-src ext/phar/phar.stub.php — @not-serializable (#23154).
            PharSerializeDeny::rejectUnserialization($className);
            // php-src ext/ffi/ffi.stub.php — @not-serializable (#23133).
            FfiSerializeDeny::rejectUnserialization($className);
            // php-src ext/enchant/enchant.stub.php — @not-serializable (#23112).
            EnchantSerializeDeny::rejectUnserialization($className);
            if (!self::isClassAllowedForUnserialize($className, $options)) {
                // Cell path so nested O: also honor allowed_classes (#29065).
                return VmUnserializeFormat::decodeToVariableWithContext($ctx, $payload, $options, $frame);
            }
            $lcEarly = strtolower($className);
            if (SplObjectStorageSerializeSupport::isSplObjectStorageClass($lcEarly)) {
                $restored = SplObjectStorageSerializeSupport::restoreFromWire($ctx, $payload, $options, $frame);
                if (null === $restored) {
                    return false;
                }
                $var = new Variable(Variable::TYPE_OBJECT);
                $var->object($restored);

                return $var;
            }
            $class = self::resolveClassEntryForUnserialize($ctx, $className);
            if (null !== $class && self::hasInstanceMethod($ctx, $class, '__unserialize')) {
                $magicData = self::decodeMagicSerializePropertyBag($ctx, $payload, $options, $frame);
                if (false === $magicData) {
                    return false;
                }

                return self::instantiateWithUnserializeData($ctx, $class, $magicData);
            }
            $parsed = self::parseCustomObjectPayload($payload);
            if (null === $parsed) {
                return false;
            }
            [$className, $data] = $parsed;
            $lcClass = strtolower($className);
            if (\is_array($data)
                && (DateTimeSupport::CLASS_DATETIME === $lcClass || DateTimeSupport::CLASS_DATETIMEIMMUTABLE === $lcClass)) {
                $restored = DateTimeSupport::restoreFromZendSerialize($ctx, $lcClass, $data);
                $var = new Variable(Variable::TYPE_OBJECT);
                $var->object($restored);

                return $var;
            }
            if (\is_array($data) && DateIntervalSupport::CLASS_DATEINTERVAL === $lcClass) {
                $restored = DateIntervalSupport::restoreFromZendSerialize($ctx, $data);
                if (null === $restored) {
                    return false;
                }
                $var = new Variable(Variable::TYPE_OBJECT);
                $var->object($restored);

                return $var;
            }
            if (\is_array($data) && SplArraySerializeSupport::isSplArrayClass($lcClass)) {
                $restored = SplArraySerializeSupport::restoreFromZendSerialize($ctx, $lcClass, $data);
                if (null === $restored) {
                    return false;
                }
                $var = new Variable(Variable::TYPE_OBJECT);
                $var->object($restored);

                return $var;
            }
            if (\is_array($data) && SplFixedArraySerializeSupport::isSplFixedArrayClass($lcClass)) {
                $restored = SplFixedArraySerializeSupport::restoreFromZendSerialize($ctx, $data);
                if (null === $restored) {
                    return false;
                }
                $var = new Variable(Variable::TYPE_OBJECT);
                $var->object($restored);

                return $var;
            }
            if (\is_array($data) && SplDllistSerializeSupport::isSplDllistClass($lcClass)) {
                $restored = SplDllistSerializeSupport::restoreFromZendSerialize($ctx, $lcClass, $data);
                if (null === $restored) {
                    return false;
                }
                $var = new Variable(Variable::TYPE_OBJECT);
                $var->object($restored);

                return $var;
            }
            if (\is_array($data) && SplObjectStorageSerializeSupport::isSplObjectStorageClass($lcClass)) {
                $restored = SplObjectStorageSerializeSupport::restoreFromWire($ctx, $payload, $options, $frame);
                if (null === $restored) {
                    return false;
                }
                $var = new Variable(Variable::TYPE_OBJECT);
                $var->object($restored);

                return $var;
            }
            if (null === $class) {
                if (!\is_array($data)) {
                    return false;
                }

                return self::instantiateIncompleteObject($ctx, $className, $data);
            }
            if (self::hasInstanceMethod($ctx, $class, '__wakeup')) {
                if (!\is_array($data)) {
                    return false;
                }

                return self::instantiateWithWakeup($ctx, $class, $data, $frame);
            }
            if (!\is_array($data)) {
                return false;
            }
            if ($class->isInterface || $class->isTrait || $class->isEnum || $class->isAbstract) {
                return false;
            }

            if (\preg_match('/^O:(\d+):"((?:[^"\\\\]|\\\\.)*)":(\d+):\{(.*)\}$/s', $payload, $m)) {
                $inner = $m[4];
                $propCount = (int) $m[3];

                return VmUnserializeFormat::decodeObjectPropertyBag(
                    $ctx,
                    $class,
                    $propCount,
                    $inner,
                    $frame,
                    $options
                );
            }

            return self::instantiatePlainObject($ctx, $class, $data, $frame);
        }

        return VmUnserializeFormat::decodeToVariableWithContext($ctx, $payload, $options, $frame);
    }

    /**
     * @param array<string, mixed>|null $options
     */
    public static function isClassAllowedForUnserialize(string $className, ?array $options): bool
    {
        if (null === $options || !\array_key_exists('allowed_classes', $options)) {
            return true;
        }
        $allowed = $options['allowed_classes'];
        if (false === $allowed) {
            return false;
        }
        if (true === $allowed) {
            return true;
        }
        if (!\is_array($allowed)) {
            throw new \TypeError(unserialize::allowedClassesOptionTypeErrorMessageFromMixed($allowed));
        }
        foreach ($allowed as $entry) {
            if (\is_string($entry) && 0 === \strcasecmp($entry, $className)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function encodeCustomObject(string $className, array $data): string
    {
        $inner = VmSerializeFormat::encodeExported($data);
        if (!str_starts_with($inner, 'a:')) {
            throw new \LogicException('serialize() failed');
        }
        $len = \strlen($className);

        return 'O:'.$len.':"'.$className.'":'.\substr($inner, 2);
    }

    /**
     * __serialize() wire encoding with object reference markers (php-src ext/standard/var.c, #11903).
     */
    private static function encodeMagicSerializeObject(
        Context $ctx,
        ObjectEntry $entry,
        Variable $data,
        ?VmSerializeRefState $state = null,
        ?Frame $frame = null
    ): string {
        $isRoot = null === $state;
        if ($isRoot) {
            $state = new VmSerializeRefState();
        }
        if (1 === $state->nextIndex) {
            $state->reserveRootSlot();
        }

        $body = '';
        $count = 0;
        foreach ($data->toArray()->iterateKeyed(true) as [$key, $value]) {
            $body .= self::encodeWireKey($key);
            $body .= self::encodeWireVariable($ctx, $value, $state, $frame);
            ++$count;
        }
        $className = $entry->class->name;
        $classLen = \strlen($className);

        return 'O:'.$classLen.':"'.$className.'":'.$count.':{'.$body.'}';
    }

    private static function encodeWireKey(Variable $key): string
    {
        $key = $key->resolveIndirect();
        if (Variable::TYPE_STRING === $key->type) {
            return VmSerializeFormat::encodeStringLiteral($key->toString());
        }
        if (Variable::TYPE_INTEGER === $key->type) {
            return 'i:'.$key->toInt().';';
        }

        throw new \LogicException(
            'serialize() only supports string or integer keys in this compiler build'
        );
    }

    /**
     * php-src php_var_serialize_intern + php_add_var_hash (ext/standard/var.c).
     *
     * Counter n always advances per visit; only ISREF cells and objects are stored for
     * R:/r: lookup. Scalars/arrays bump n but are never emitted as R: on re-visit — so
     * `$a=[1]; $a[]=&$a` re-emits the scalar inside the nested self-ref walk (#22653).
     * ISREF→object hashes as the object and still emits R: (not r:) on revisit.
     */
    private static function encodeWireVariable(
        Context $ctx,
        Variable $value,
        VmSerializeRefState $state,
        ?Frame $frame = null
    ): string {
        if ($value->isIndirect()) {
            $target = $value->resolveIndirect();
            // php_add_var_hash: ISREF to object is keyed by the object, emit R: on revisit.
            if (Variable::TYPE_OBJECT === $target->type) {
                $enumRef = self::enumCaseRefFromVariable($target);
                if (null !== $enumRef) {
                    $state->bumpIndex();

                    return self::encodeEnumCaseLiteral($enumRef->className, $enumRef->caseName);
                }
                $object = $target->toObject();
                $existingObject = $state->lookupObjectIndex($object);
                if (null !== $existingObject) {
                    return 'R:'.$existingObject.';';
                }

                return self::encodeWireObject($ctx, $object, $state, $frame);
            }
            // php-src keys ISREF by zend_reference; VM shared-ref identity is the
            // resolved target Variable (all aliases point at the same cell).
            $existingRef = $state->lookupRefCellIndex($target);
            if (null !== $existingRef) {
                return 'R:'.$existingRef.';';
            }
            // First sight of this ISREF set: store index, unwrap without a second n bump
            // (php-src IS_REFERENCE → goto again skips php_add_var_hash).
            $state->assignRefCellIndex($target);

            return self::encodeWireValueAfterRefUnwrap($ctx, $target, $state, $frame);
        }

        $value = $value->resolveIndirect();
        if (Variable::TYPE_OBJECT === $value->type) {
            $enumRef = self::enumCaseRefFromVariable($value);
            if (null !== $enumRef) {
                $state->bumpIndex();

                return self::encodeEnumCaseLiteral($enumRef->className, $enumRef->caseName);
            }

            return self::encodeWireObject($ctx, $value->toObject(), $state, $frame);
        }
        // Non-object, non-ref: bump n only (not hashed).
        $state->bumpIndex();

        return self::encodeWireValueAfterRefUnwrap($ctx, $value, $state, $frame);
    }

    /** Encode array/scalar/resource after n was already accounted for (var.c goto again / bump-only). */
    private static function encodeWireValueAfterRefUnwrap(
        Context $ctx,
        Variable $value,
        VmSerializeRefState $state,
        ?Frame $frame = null
    ): string {
        $value = $value->resolveIndirect();
        $resourceWire = self::serializeResourceWire($value);
        if (null !== $resourceWire) {
            return $resourceWire;
        }
        $enumRef = self::enumCaseRefFromVariable($value);
        if (null !== $enumRef) {
            return self::encodeEnumCaseLiteral($enumRef->className, $enumRef->caseName);
        }
        if (Variable::TYPE_OBJECT === $value->type) {
            return self::encodeWireObject($ctx, $value->toObject(), $state, $frame);
        }
        if (Variable::TYPE_ARRAY === $value->type) {
            return self::encodeWireArray($ctx, $value, $state, $frame);
        }

        return VmSerializeFormat::encodeExported(VmJson::export($value, $ctx, $ctx->runtime->vm));
    }

    /** Public wrapper for SPL/custom serializers embedding nested values (#14164). */
    public static function encodeVariableWire(
        Context $ctx,
        Variable $value,
        VmSerializeRefState $state,
        ?Frame $frame = null
    ): string {
        return self::encodeWireVariable($ctx, $value, $state, $frame);
    }

    private static function encodeWireArray(
        Context $ctx,
        Variable $value,
        VmSerializeRefState $state,
        ?Frame $frame = null
    ): string {
        $value = $value->resolveIndirect();
        $body = '';
        $count = 0;
        foreach ($value->toArray()->iterateKeyed(false) as [$key, $elem]) {
            $body .= self::encodeWireKey($key);
            $body .= self::encodeWireVariable($ctx, $elem, $state, $frame);
            ++$count;
        }

        return 'a:'.$count.':{'.$body.'}';
    }

    private static function encodeWireObject(
        Context $ctx,
        ObjectEntry $entry,
        VmSerializeRefState $state,
        ?Frame $frame = null
    ): string {
        if (EnumCaseSupport::isEnumCase($entry)) {
            $state->assignObjectIndex($entry);

            return self::encodeEnumCaseLiteral($entry->class->name, $entry->enumCaseName ?? '');
        }
        if (0 === strcasecmp($entry->class->name, 'Closure')) {
            throw new \Exception("Serialization of 'Closure' is not allowed");
        }
        // php-src Zend/zend_fibers.c — zend_class_serialize_deny (#23043).
        if (0 === strcasecmp($entry->class->name, 'Fiber')) {
            throw new \Exception("Serialization of 'Fiber' is not allowed");
        }
        // php-src Zend/zend_generators.c — zend_class_serialize_deny (#23044).
        if (0 === strcasecmp($entry->class->name, 'Generator')) {
            throw new \Exception("Serialization of 'Generator' is not allowed");
        }
        // php-src Zend/zend_weakrefs.c — ZEND_ACC_NOT_SERIALIZABLE (#23062, #23063).
        if (0 === strcasecmp($entry->class->name, 'WeakMap')) {
            throw new \Exception("Serialization of 'WeakMap' is not allowed");
        }
        if (0 === strcasecmp($entry->class->name, 'WeakReference')) {
            throw new \Exception("Serialization of 'WeakReference' is not allowed");
        }
        // php-src Zend/zend_exceptions.c — @not-serializable SensitiveParameterValue (#23086).
        if (0 === strcasecmp($entry->class->name, 'SensitiveParameterValue')) {
            throw new \Exception("Serialization of 'SensitiveParameterValue' is not allowed");
        }
        // php-src ext/curl/curl_file.stub.php — @not-serializable (#23064).
        CurlFileSerializeDeny::rejectSerialization($entry->class->name);
        // php-src ext/dom/php_dom.stub.php — @not-serializable DOMXPath / Dom\XPath (#23088).
        DomXPathSerializeDeny::rejectSerialization($entry->class->name);
        // php-src ext/fileinfo/fileinfo.stub.php — @not-serializable (#23093).
        FinfoSerializeDeny::rejectSerialization($entry->class->name);
        // php-src ext/ftp/ftp.stub.php — @not-serializable (#23134).
        FtpSerializeDeny::rejectSerialization($entry->class->name);
        // php-src ext/intl/*.stub.php — @not-serializable (#23092).
        IntlSerializeDeny::rejectSerialization($entry->class->name, $ctx);
        // php-src ext/sockets/sockets.stub.php — @not-serializable (#23094).
        SocketSerializeDeny::rejectSerialization($entry->class->name);
        // php-src ext/sysvmsg|sysvsem|sysvshm|shmop — @not-serializable (#23132).
        SysvIpcSerializeDeny::rejectSerialization($entry->class->name);
        // php-src ext/openssl/openssl.stub.php — @not-serializable (#23100).
        OpensslSerializeDeny::rejectSerialization($entry->class->name);
        // php-src ext/pdo/pdo_dbh.stub.php + pdo_stmt.stub.php — @not-serializable (#23103).
        PdoSerializeDeny::rejectSerialization($entry->class->name);
        // php-src ext/pgsql/pgsql.stub.php — @not-serializable (#23135).
        PgsqlSerializeDeny::rejectSerialization($entry->class->name);
        // php-src ext/ldap/ldap.stub.php — @not-serializable (#23169).
        LdapSerializeDeny::rejectSerialization($entry->class->name);
        // php-src ext/zlib/zlib.stub.php — @not-serializable (#23101).
        ZlibContextSerializeDeny::rejectSerialization($entry->class->name);
        // php-src ext/random/random.stub.php — @not-serializable Random\Engine\Secure (#23102).
        RandomSecureSerializeDeny::rejectSerialization($entry->class->name);
        // php-src ext/xml/xml.stub.php — @not-serializable XMLParser (#23111).
        XmlParserSerializeDeny::rejectSerialization($entry->class->name);
        // php-src ext/reflection/php_reflection.stub.php — @not-serializable (#23087).
        ReflectionSerializeDeny::rejectSerialization($entry->class->name);
        // php-src ext/simplexml/sxe.c — zend_class_serialize_deny (#23072).
        SimpleXmlSerializeDeny::rejectSerialization($entry->class->name, $ctx);
        // php-src ext/sqlite3/sqlite3.stub.php — @not-serializable (#23137).
        Sqlite3SerializeDeny::rejectSerialization($entry->class->name);
        // php-src ext/dba/dba.stub.php — @not-serializable (#23113).
        DbaSerializeDeny::rejectSerialization($entry->class->name);
        // php-src ext/phar/phar.stub.php — @not-serializable (#23154).
        PharSerializeDeny::rejectSerialization($entry->class->name);
        // php-src ext/ffi/ffi.stub.php — @not-serializable (#23133).
        FfiSerializeDeny::rejectSerialization($entry->class->name);
        // php-src ext/enchant/enchant.stub.php — @not-serializable (#23112).
        EnchantSerializeDeny::rejectSerialization($entry->class->name);
        // php-src Zend/zend_interfaces.stub.php — @not-serializable InternalIterator (#23167).
        InternalIteratorSerializeDeny::rejectSerialization($entry->class->name);
        // Zend ZEND_PROP_PURPOSE_SERIALIZE — initialize lazy objects unless SKIP_INITIALIZATION_ON_SERIALIZE (#21126).
        if ($entry->lazyPending && LazyObjectSupport::shouldInitializeOnSerialize($entry)) {
            $vm = $ctx->runtime->vm ?? null;
            if (null === $vm) {
                throw new \LogicException('serialize() of lazy objects requires VM');
            }
            LazyObjectSupport::ensureInitialized($vm, $entry);
            $entry = LazyObjectSupport::getLazyInstance($entry);
        }
        SplFileIteratorSerializeDeny::rejectSerialization($entry->class->name);
        self::rejectAnonymousClassSerialization($entry);
        $existing = $state->lookupObjectIndex($entry);
        if (null !== $existing) {
            return 'r:'.$existing.';';
        }

        $lcClass = strtolower($entry->class->name);
        if (DateTimeSupport::CLASS_DATETIME === $lcClass || DateTimeSupport::CLASS_DATETIMEIMMUTABLE === $lcClass) {
            $state->assignObjectIndex($entry);

            return DateTimeSupport::encodeZendSerializeWire($entry);
        }
        if (DateIntervalSupport::CLASS_DATEINTERVAL === $lcClass) {
            $state->assignObjectIndex($entry);

            return DateIntervalSupport::encodeZendSerializeWire($entry);
        }
        if (SplArraySerializeSupport::isSplArrayClass($lcClass)) {
            $state->assignObjectIndex($entry);

            return SplArraySerializeSupport::encodeZendSerializeWire($entry);
        }
        if (SplFixedArraySerializeSupport::isSplFixedArrayClass($lcClass)) {
            $state->assignObjectIndex($entry);

            return SplFixedArraySerializeSupport::encodeZendSerializeWire($entry);
        }
        if (SplDllistSerializeSupport::isSplDllistClass($lcClass)) {
            $state->assignObjectIndex($entry);

            return SplDllistSerializeSupport::encodeZendSerializeWire($entry);
        }
        if (SplObjectStorageSerializeSupport::isSplObjectStorageClass($lcClass)) {
            $state->assignObjectIndex($entry);

            return SplObjectStorageSerializeSupport::encodeZendSerializeWire($ctx, $entry, $state, $frame);
        }
        if (self::CLASS_INCOMPLETE === $lcClass) {
            $state->assignObjectIndex($entry);

            return self::encodeIncompleteObjectWire($ctx, $entry, $state, $frame);
        }
        if (self::hasInstanceMethod($ctx, $entry->class, '__serialize')) {
            if (null !== $ctx->magicSerializeBeingInvoked || 1 !== $state->nextIndex) {
                $state->assignObjectIndex($entry);
            }
            $prevMagic = $ctx->magicSerializeBeingInvoked;
            $ctx->magicSerializeBeingInvoked = $entry;
            try {
                $magicData = self::invokeSerialize($ctx, $entry);
                if (Variable::TYPE_ARRAY !== $magicData->type) {
                    self::throwSerializeMustReturnArray($entry->class->name);
                }

                return self::encodeMagicSerializeObject($ctx, $entry, $magicData, $state, $frame);
            } finally {
                $ctx->magicSerializeBeingInvoked = $prevMagic;
            }
        }
        if (self::implementsLegacySerializable($entry->class)) {
            if (null === $ctx->legacySerializableBeingInvoked) {
                if (1 === $state->nextIndex) {
                    $state->reserveRootSlot();
                } else {
                    $state->assignObjectIndex($entry);
                }
            } else {
                $state->assignObjectIndex($entry);
            }
            $prevLegacy = $ctx->legacySerializableBeingInvoked;
            $ctx->legacySerializableBeingInvoked = $entry;
            try {
                $payload = self::invokeLegacySerializableSerialize($ctx, $entry);

                return self::encodeSerializableObject($entry->class->name, $payload);
            } finally {
                $ctx->legacySerializableBeingInvoked = $prevLegacy;
            }
        }
        $state->assignObjectIndex($entry);
        if (self::hasInstanceMethod($ctx, $entry->class, '__sleep')) {
            return self::encodeSleepObject($ctx, $entry, $frame);
        }

        return self::encodePlainObjectWire($ctx, $entry, $frame, $state);
    }

    /** Zend Serializable custom object format: C:len:"Class":datalen:{payload} */
    public static function encodeSerializableObject(string $className, string $payload): string
    {
        $classLen = \strlen($className);
        $dataLen = \strlen($payload);

        return 'C:'.$classLen.':"'.$className.'":'.$dataLen.':{'.$payload.'}';
    }

    /**
     * @return array{0: string, 1: int, 2: string}|null
     */
    public static function parseObjectWireHeader(string $payload): ?array
    {
        if (!\preg_match('/^O:(\d+):"((?:[^"\\\\]|\\\\.)*)":(\d+):\{(.*)\}$/s', $payload, $m)) {
            return null;
        }
        $declaredLen = (int) $m[1];
        $className = self::unescapeSerializedClassName($m[2]);
        if (\strlen($className) !== $declaredLen) {
            return null;
        }

        return [$className, (int) $m[3], $m[4]];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    public static function parseCustomObjectPayload(string $payload): ?array
    {
        if (!\preg_match('/^O:(\d+):"((?:[^"\\\\]|\\\\.)*)":(\d+):\{(.*)\}$/s', $payload, $m)) {
            return null;
        }
        $declaredLen = (int) $m[1];
        $className = self::unescapeSerializedClassName($m[2]);
        if (\strlen($className) !== $declaredLen) {
            return null;
        }
        $arrayPayload = 'a:'.$m[3].':{'.$m[4].'}';
        $data = VmUnserializeFormat::decodePayload($arrayPayload);
        if (false === $data || !\is_array($data)) {
            return null;
        }

        return [$className, $data];
    }

    /**
     * Unescape O:/C: class names without stripping namespace separators (php-src var.c; #13296).
     */
    private static function unescapeSerializedClassName(string $wire): string
    {
        return str_replace(['\\\\', '\\"'], ['\\', '"'], $wire);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    public static function parseSerializableObjectPayload(string $payload): ?array
    {
        if (!\preg_match('/^C:(\d+):"((?:[^"\\\\]|\\\\.)*)":(\d+):\{(.+)\}$/s', $payload, $m)) {
            return null;
        }
        $declaredLen = (int) $m[1];
        $className = self::unescapeSerializedClassName($m[2]);
        if (\strlen($className) !== $declaredLen) {
            return null;
        }
        $dataLen = (int) $m[3];
        $data = $m[4];
        if (\strlen($data) !== $dataLen) {
            return null;
        }

        return [$className, $data];
    }

    public static function instantiateWithUnserialize(
        Context $ctx,
        ClassEntry $class,
        array $data
    ): Variable {
        return self::instantiateWithUnserializeData($ctx, $class, VmJson::import($data));
    }

    public static function instantiateWithUnserializeData(
        Context $ctx,
        ClassEntry $class,
        Variable $dataVar
    ): Variable {
        // Resolve parent tables too (SplStack/SplQueue inherit SplDoublyLinkedList::__unserialize; #23368).
        $method = self::resolveInstanceMethod($ctx, $class, '__unserialize');
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        $recv = new Variable();
        $recv->object($entry);
        if ($method instanceof VmClassMethod) {
            self::invokeBuiltinClassMethod($ctx, $method, $entry, $dataVar);

            return $recv;
        }
        if (!$method instanceof PhpFunc) {
            throw new \LogicException(
                'Class '.$class->name.'::__unserialize() must be a user method in this compiler build'
            );
        }
        $ctx->runtime->vm->invokePhpFunctionIsolated($method, $recv, $dataVar);

        return $recv;
    }

    /**
     * Decode O: wire whose class uses __serialize() — nested objects need Context (#13476).
     *
     * @param array<string, mixed>|null $options
     */
    public static function decodeMagicSerializePropertyBag(
        Context $ctx,
        string $payload,
        ?array $options = null,
        ?Frame $frame = null
    ): Variable|false {
        $header = self::parseObjectWireHeader($payload);
        if (null === $header) {
            return false;
        }
        [, $propCount, $inner] = $header;
        $arrayPayload = 'a:'.$propCount.':{'.$inner.'}';

        return VmUnserializeFormat::decodeToVariableWithContext($ctx, $arrayPayload, $options, $frame);
    }

    /**
     * Zend var_unserializer.c — plain O: object with property bag (no __unserialize/__wakeup; #5140).
     */
    public static function instantiatePlainObject(
        Context $ctx,
        ClassEntry $class,
        array $data,
        ?Frame $frame = null
    ): Variable {
        $entry = new ObjectEntry($class);
        // Zend restores serialized props on a live object; hooks must run (#6474).
        $entry->constructed = true;
        self::restoreObjectProperties($ctx, $entry, $data, $frame);
        $recv = new Variable();
        $recv->object($entry);

        return $recv;
    }

    public static function instantiateWithWakeup(
        Context $ctx,
        ClassEntry $class,
        array $data,
        ?Frame $frame = null
    ): Variable {
        $entry = new ObjectEntry($class);
        // Zend marks the object constructed before/around __wakeup (var_unserializer.c; #26673).
        $entry->constructed = true;
        self::restoreObjectProperties($ctx, $entry, $data, $frame);
        $method = self::resolveInstanceMethod($ctx, $class, '__wakeup');
        if ($method instanceof VmClassMethod) {
            self::invokeBuiltinClassMethod($ctx, $method, $entry);

            $recv = new Variable();
            $recv->object($entry);

            return $recv;
        }
        if (!$method instanceof PhpFunc) {
            throw new \LogicException(
                'Class '.$class->name.'::__wakeup() must be a user method in this compiler build'
            );
        }
        $recv = new Variable();
        $recv->object($entry);
        $ctx->runtime->vm->invokePhpFunctionIsolated($method, $recv);

        return $recv;
    }

    public static function instantiateLegacySerializable(
        Context $ctx,
        ClassEntry $class,
        string $data
    ): Variable {
        $method = $class->methods['unserialize'] ?? null;
        if (!$method instanceof PhpFunc) {
            throw new \LogicException(
                'Class '.$class->name.'::unserialize() must be a user method in this compiler build'
            );
        }
        $entry = new ObjectEntry($class);
        $recv = new Variable();
        $recv->object($entry);
        $dataVar = new Variable();
        $dataVar->string($data);
        // Isolated stack: nested Serializable::unserialize must not resume the caller (#25189, #11452).
        $ctx->runtime->vm->invokePhpFunctionIsolated($method, $recv, $dataVar);

        return $recv;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    public static function parseEnumCasePayload(string $payload): ?array
    {
        if (!\preg_match('/^E:(\d+):"((?:[^"\\\\]|\\\\.)*)";$/', $payload, $m)) {
            return null;
        }
        $declaredLen = (int) $m[1];
        $inner = $m[2];
        if (\strlen($inner) !== $declaredLen) {
            return null;
        }
        $unescaped = self::unescapeSerializedEnumPayload($inner);
        $colonPos = strrpos($unescaped, ':');
        if (false === $colonPos || 0 === $colonPos) {
            return null;
        }
        $className = \substr($unescaped, 0, $colonPos);
        $caseName = \substr($unescaped, $colonPos + 1);
        if ('' === $className || '' === $caseName) {
            return null;
        }

        return [$className, $caseName];
    }

    public static function resolveEnumCaseVariable(
        Context $ctx,
        string $className,
        string $caseName
    ): ?Variable {
        $lc = strtolower($className);
        if (!isset($ctx->classes[$lc])) {
            $ctx->autoloadClass($className);
        }
        if (!isset($ctx->classes[$lc])) {
            return null;
        }
        $enum = $ctx->classes[$lc];
        if (!$enum->isEnum) {
            return null;
        }
        $canonical = BackedEnum::canonicalCaseVariable($enum, $caseName);
        if (null === $canonical) {
            return null;
        }
        $resolved = $canonical->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $resolved->type
            || (Variable::TYPE_OBJECT === $resolved->type && EnumCaseSupport::isEnumCase($resolved->toObject()))) {
            $var = new Variable();
            $var->copyFrom($resolved);

            return $var;
        }

        return null;
    }

    /**
     * Resolve enum case from the raw "ClassName:CaseName" payload extracted by VmUnserializeFormat (#23692).
     */
    public static function resolveEnumCaseFromWirePayload(Context $ctx, string $payload): ?Variable
    {
        $colonPos = strrpos($payload, ':');
        if (false === $colonPos || 0 === $colonPos) {
            return null;
        }
        $className = \substr($payload, 0, $colonPos);
        $caseName = \substr($payload, $colonPos + 1);
        if ('' === $className || '' === $caseName) {
            return null;
        }

        return self::resolveEnumCaseVariable($ctx, $className, $caseName);
    }

    /**
     * php-src ext/standard/var.c — resource zvals serialize as integer wire (i:N;).
     * PHP 8.4 Resource objects use id 0; closed handles must not leak stale ids (#5326).
     */
    private static function serializeResourceWire(Variable $value): ?string
    {
        if (!ResourceSupport::isVmResource($value)) {
            return null;
        }

        return 'i:0;';
    }

    private static function exportForSerialize(Context $ctx, Variable $value): mixed
    {
        $value = $value->resolveIndirect();
        if (VmClosureCall::isClosure($value)) {
            throw new \Exception("Serialization of 'Closure' is not allowed");
        }
        if (Variable::TYPE_OBJECT === $value->type
            && 0 === strcasecmp($value->toObject()->class->name, 'Fiber')) {
            throw new \Exception("Serialization of 'Fiber' is not allowed");
        }
        if (Variable::TYPE_OBJECT === $value->type
            && 0 === strcasecmp($value->toObject()->class->name, 'Generator')) {
            throw new \Exception("Serialization of 'Generator' is not allowed");
        }
        if (Variable::TYPE_OBJECT === $value->type
            && 0 === strcasecmp($value->toObject()->class->name, 'WeakMap')) {
            throw new \Exception("Serialization of 'WeakMap' is not allowed");
        }
        if (Variable::TYPE_OBJECT === $value->type
            && 0 === strcasecmp($value->toObject()->class->name, 'WeakReference')) {
            throw new \Exception("Serialization of 'WeakReference' is not allowed");
        }
        if (Variable::TYPE_OBJECT === $value->type
            && 0 === strcasecmp($value->toObject()->class->name, 'SensitiveParameterValue')) {
            throw new \Exception("Serialization of 'SensitiveParameterValue' is not allowed");
        }
        if (Variable::TYPE_OBJECT === $value->type) {
            CurlFileSerializeDeny::rejectSerialization($value->toObject()->class->name);
            DomXPathSerializeDeny::rejectSerialization($value->toObject()->class->name);
            FinfoSerializeDeny::rejectSerialization($value->toObject()->class->name);
            FtpSerializeDeny::rejectSerialization($value->toObject()->class->name);
            IntlSerializeDeny::rejectSerialization($value->toObject()->class->name, $ctx);
            SocketSerializeDeny::rejectSerialization($value->toObject()->class->name);
            SysvIpcSerializeDeny::rejectSerialization($value->toObject()->class->name);
            OpensslSerializeDeny::rejectSerialization($value->toObject()->class->name);
            PdoSerializeDeny::rejectSerialization($value->toObject()->class->name);
            PgsqlSerializeDeny::rejectSerialization($value->toObject()->class->name);
            LdapSerializeDeny::rejectSerialization($value->toObject()->class->name);
            ZlibContextSerializeDeny::rejectSerialization($value->toObject()->class->name);
            RandomSecureSerializeDeny::rejectSerialization($value->toObject()->class->name);
            XmlParserSerializeDeny::rejectSerialization($value->toObject()->class->name);
            ReflectionSerializeDeny::rejectSerialization($value->toObject()->class->name);
            SimpleXmlSerializeDeny::rejectSerialization($value->toObject()->class->name, $ctx);
            Sqlite3SerializeDeny::rejectSerialization($value->toObject()->class->name);
            DbaSerializeDeny::rejectSerialization($value->toObject()->class->name);
            PharSerializeDeny::rejectSerialization($value->toObject()->class->name);
            FfiSerializeDeny::rejectSerialization($value->toObject()->class->name);
            EnchantSerializeDeny::rejectSerialization($value->toObject()->class->name);
            InternalIteratorSerializeDeny::rejectSerialization($value->toObject()->class->name);
        }
        $enumRef = self::enumCaseRefFromVariable($value);
        if (null !== $enumRef) {
            return $enumRef;
        }
        if (Variable::TYPE_ARRAY === $value->type) {
            $out = [];
            foreach ($value->toArray()->iterateKeyed(true) as [$key, $elem]) {
                $k = $key->resolveIndirect();
                if (Variable::TYPE_STRING === $k->type) {
                    $out[$k->toString()] = self::exportForSerialize($ctx, $elem);
                } elseif (Variable::TYPE_INTEGER === $k->type) {
                    $out[$k->toInt()] = self::exportForSerialize($ctx, $elem);
                } else {
                    throw new \LogicException(
                        'serialize() only supports string or integer keys in this compiler build'
                    );
                }
            }

            return $out;
        }

        return VmJson::export($value, $ctx, $ctx->runtime->vm);
    }

    /** Unescape php-src serialize string escapes in enum E: payloads (var_unserializer.c). */
    private static function unescapeSerializedEnumPayload(string $payload): string
    {
        return \preg_replace_callback(
            '/\\\\([\\\\"nrt])/',
            static function (array $m): string {
                return match ($m[1]) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    default => $m[1],
                };
            },
            $payload
        ) ?? $payload;
    }

    private static function enumCaseRefFromVariable(Variable $value): ?VmSerializeEnumCaseRef
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $value->type) {
            $case = $value->toEnumCase();

            return new VmSerializeEnumCaseRef($case->enumClass->name, $case->caseName);
        }
        if (Variable::TYPE_OBJECT === $value->type && EnumCaseSupport::isEnumCase($value->toObject())) {
            $object = $value->toObject();

            return new VmSerializeEnumCaseRef($object->class->name, $object->enumCaseName ?? '');
        }

        return null;
    }

    private static function encodeSleepObject(Context $ctx, ObjectEntry $entry, ?Frame $frame = null): string
    {
        $names = self::collectSleepPropertyNames($ctx, $entry, $frame);
        if (null === $names) {
            return 'N;';
        }
        /** @var array<string, Variable> $props */
        $props = [];
        /** @var array<string, true> $seenKeys */
        $seenKeys = [];
        foreach ($names as $name) {
            $resolved = self::resolveSleepProperty($ctx, $entry, $name, $frame);
            if (null === $resolved) {
                continue;
            }
            [$key, $value] = $resolved;
            if (isset($seenKeys[$key])) {
                // php-src php_var_serialize_try_add_sleep_prop — duplicate __sleep name.
                $ctx->errors->triggerError(
                    \sprintf('serialize(): "%s" is returned from __sleep() multiple times', $name),
                    ErrorReporter::E_WARNING,
                    null,
                    $ctx,
                    $frame
                );
                continue;
            }
            $seenKeys[$key] = true;
            // Uninitialized typed slots: found but omitted (var.c IS_UNDEF + typed info).
            if (TypedPropertyCheck::omitFromSerialize($value)) {
                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($value);
            $props[$key] = $copy;
        }

        return self::encodeObjectPropertyBag($ctx, $entry->class->name, $props);
    }

    /**
     * php-src php_var_serialize_get_sleep_props — try public/dynamic, then private(ce), then protected.
     *
     * @return array{0: string, 1: Variable}|null wire key + value, or null after "does not exist" warning
     */
    private static function resolveSleepProperty(
        Context $ctx,
        ObjectEntry $entry,
        string $name,
        ?Frame $frame
    ): ?array {
        // 1) Public declared or dynamic — unmangled key in ZEND_PROP_PURPOSE_SERIALIZE bag.
        if ($entry->hasProperty($name)) {
            $meta = VmReflection::findClassPropertyExact($entry->class, $name, $ctx);
            if (null === $meta || MethodVisibility::isPublic($meta->visibility)) {
                return [$name, $entry->getProperty($name)->resolveIndirect()];
            }
        }

        // 2) Private of the object's class (mangle with ce->name).
        $privMeta = self::findSleepPrivatePropertyOnClass($entry->class, $name);
        if (null !== $privMeta && $entry->hasPropertyForMeta($privMeta)) {
            return [
                VmReflection::manglePropertyKey($privMeta, $ctx),
                $entry->getPropertyForMeta($privMeta)->resolveIndirect(),
            ];
        }

        // 3) Protected in the hierarchy (mangle as "\0*\0name").
        $protMeta = self::findSleepProtectedProperty($entry->class, $name, $ctx);
        if (null !== $protMeta && $entry->hasPropertyForMeta($protMeta)) {
            return [
                VmReflection::manglePropertyKey($protMeta, $ctx),
                $entry->getPropertyForMeta($protMeta)->resolveIndirect(),
            ];
        }

        $ctx->errors->triggerError(
            \sprintf(
                'serialize(): "%s" returned as member variable from __sleep() but does not exist',
                $name
            ),
            ErrorReporter::E_WARNING,
            null,
            $ctx,
            $frame
        );

        return null;
    }

    private static function findSleepPrivatePropertyOnClass(ClassEntry $class, string $name): ?ClassProperty
    {
        // php-src mangles with ce->name only — parent private slots live on the child CE
        // for storage (#22521) but must not match __sleep lookup (var.c).
        $ceLc = strtolower(ltrim($class->name, '\\'));
        foreach ($class->properties as $meta) {
            if ($meta->name !== $name) {
                continue;
            }
            if (($meta->visibility & \PHPCfg\Func::FLAG_PRIVATE) === 0) {
                continue;
            }
            $declLc = '' !== $meta->declaringClassLc
                ? $meta->declaringClassLc
                : $ceLc;
            if ($declLc === $ceLc) {
                return $meta;
            }
        }

        return null;
    }

    private static function findSleepProtectedProperty(
        ClassEntry $class,
        string $name,
        Context $ctx
    ): ?ClassProperty {
        foreach (VmReflection::classHierarchyChain($class, $ctx) as $scan) {
            foreach ($scan->properties as $meta) {
                if ($meta->name !== $name) {
                    continue;
                }
                if (($meta->visibility & \PHPCfg\Func::FLAG_PROTECTED) !== 0) {
                    return $meta;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>|null null when __sleep() did not return an array (php-src var.c; #13378)
     */
    private static function collectSleepPropertyNames(Context $ctx, ObjectEntry $entry, ?Frame $frame = null): ?array
    {
        $method = self::resolveInstanceMethod($ctx, $entry->class, '__sleep');
        if ($method instanceof VmClassMethod) {
            // Builtin __sleep (e.g. DOMNode) may throw; otherwise must return string[].
            $result = self::invokeBuiltinClassMethod($ctx, $method, $entry);
            if (Variable::TYPE_ARRAY !== $result->type) {
                $ctx->errors->triggerError(
                    'serialize(): '.$entry->class->name.'::__sleep() should return an array only containing the names of instance-variables to serialize',
                    ErrorReporter::E_WARNING,
                    null,
                    $ctx,
                    $frame
                );

                return null;
            }
            $names = [];
            foreach ($result->toArray()->iterateKeyed(true) as [, $elem]) {
                $elem = $elem->resolveIndirect();
                if (Variable::TYPE_STRING !== $elem->type) {
                    $ctx->errors->triggerError(
                        'serialize(): '.$entry->class->name.'::__sleep() should return an array only containing the names of instance-variables to serialize',
                        ErrorReporter::E_WARNING,
                        null,
                        $ctx,
                        $frame
                    );
                }
                $names[] = $elem->toString();
            }

            return $names;
        }
        if (!$method instanceof PhpFunc) {
            throw new \LogicException(
                'Class '.$entry->class->name.'::__sleep() must be a user method in this compiler build'
            );
        }
        $recv = new Variable();
        $recv->object($entry);
        // Isolated stack: nested __sleep must not resume the caller frame mid-builtin (#25189, #11452).
        $result = $ctx->runtime->vm->invokePhpFunctionIsolated($method, $recv);
        if (Variable::TYPE_ARRAY !== $result->type) {
            $ctx->errors->triggerError(
                'serialize(): '.$entry->class->name.'::__sleep() should return an array only containing the names of instance-variables to serialize',
                ErrorReporter::E_WARNING,
                null,
                $ctx,
                $frame
            );

            return null;
        }
        $names = [];
        foreach ($result->toArray()->iterateKeyed(true) as [, $elem]) {
            $elem = $elem->resolveIndirect();
            if (Variable::TYPE_STRING !== $elem->type) {
                $ctx->errors->triggerError(
                    'serialize(): '.$entry->class->name.'::__sleep() should return an array only containing the names of instance-variables to serialize',
                    ErrorReporter::E_WARNING,
                    null,
                    $ctx,
                    $frame
                );
            }
            $names[] = $elem->toString();
        }

        return $names;
    }

    /**
     * Zend php_var_serialize() for __PHP_Incomplete_Class — emit original class name (var.c, #10765).
     */
    private static function encodeIncompleteObjectWire(
        Context $ctx,
        ObjectEntry $entry,
        ?VmSerializeRefState $state = null,
        ?Frame $frame = null
    ): string {
        if (!$entry->hasProperty(self::INCOMPLETE_CLASS_NAME_PROP)) {
            throw new \LogicException(
                '__PHP_Incomplete_Class object missing '.self::INCOMPLETE_CLASS_NAME_PROP.' property'
            );
        }
        $originalClass = $entry->getProperty(self::INCOMPLETE_CLASS_NAME_PROP)->resolveIndirect()->toString();

        $isRoot = null === $state;
        if ($isRoot) {
            $state = new VmSerializeRefState();
            $state->objectIndex[$entry] = 1;
            $state->nextIndex = 2;
        } elseif (null === $state->lookupObjectIndex($entry)) {
            $state->assignObjectIndex($entry);
        }

        $body = '';
        $count = 0;
        foreach ($entry->getRawProperties() as $name => $prop) {
            if (self::INCOMPLETE_CLASS_NAME_PROP === $name) {
                continue;
            }
            $value = $prop->resolveIndirect();
            if (TypedPropertyCheck::omitFromSerialize($value)) {
                continue;
            }
            $keyVar = new Variable();
            $keyVar->string($name);
            $body .= self::encodeWireKey($keyVar);
            $body .= self::encodeWireVariable($ctx, $value, $state, $frame);
            ++$count;
        }
        $classLen = \strlen($originalClass);

        return 'O:'.$classLen.':"'.$originalClass.'":'.$count.':{'.$body.'}';
    }

    /**
     * Zend plain object wire with object-reference markers (ext/standard/var.c, #12082).
     */
    private static function encodePlainObjectWire(
        Context $ctx,
        ObjectEntry $entry,
        ?Frame $frame = null,
        ?VmSerializeRefState $state = null
    ): string {
        $isRoot = null === $state;
        if ($isRoot) {
            $state = new VmSerializeRefState();
            $state->objectIndex[$entry] = 1;
            $state->nextIndex = 2;
        } elseif (null === $state->lookupObjectIndex($entry)) {
            $state->assignObjectIndex($entry);
        }

        $body = '';
        $count = 0;
        foreach (self::collectPlainObjectSerializeProperties($ctx, $entry, $frame) as $name => $value) {
            $keyVar = new Variable();
            $keyVar->string($name);
            $body .= self::encodeWireKey($keyVar);
            $body .= self::encodeWireVariable($ctx, $value, $state, $frame);
            ++$count;
        }
        $className = $entry->class->name;
        $classLen = \strlen($className);

        return 'O:'.$classLen.':"'.$className.'":'.$count.':{'.$body.'}';
    }

    /**
     * Zend php_var_serialize() plain object branch — all declared props + dynamic props (#3621, #15751, var.c).
     */
    private static function encodePlainObject(Context $ctx, ObjectEntry $entry, ?Frame $frame = null): string
    {
        return self::encodePlainObjectWire($ctx, $entry, $frame);
    }

    /**
     * @return array<string, Variable>
     */
    private static function collectPlainObjectSerializeProperties(
        Context $ctx,
        ObjectEntry $entry,
        ?Frame $frame = null
    ): array {
        if (null !== $frame) {
            return $ctx->runtime->vm()->collectObjectPropertiesForSerialize($entry, $frame);
        }

        /** @var array<string, Variable> $props */
        $props = [];
        /** @var array<string, true> $seenLc */
        $seenLc = [];
        foreach (array_reverse(VmReflection::classHierarchyChain($entry->class, $ctx)) as $class) {
            foreach ($class->properties as $meta) {
                $lc = strtolower($meta->name);
                if (isset($seenLc[$lc])) {
                    continue;
                }
                $seenLc[$lc] = true;
                if (!$entry->hasProperty($meta->name)) {
                    continue;
                }
                $value = $entry->getProperty($meta->name)->resolveIndirect();
                if (TypedPropertyCheck::omitFromSerialize($value)) {
                    continue;
                }
                $copy = new Variable();
                $copy->copyFrom($value);
                $props[VmReflection::manglePropertyKey($meta, $ctx)] = $copy;
            }
        }
        foreach ($entry->getRawProperties() as $name => $prop) {
            if (isset($seenLc[strtolower($name)])) {
                continue;
            }
            $value = $prop->resolveIndirect();
            if (TypedPropertyCheck::omitFromSerialize($value)) {
                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($value);
            $props[$name] = $copy;
        }

        return $props;
    }

    /**
     * @param array<string, Variable> $props
     */
    private static function encodeObjectPropertyBag(Context $ctx, string $className, array $props): string
    {
        $body = '';
        foreach ($props as $name => $value) {
            $body .= self::encodeSerializedScalar($name);
            $body .= self::encodeSerializedValue($ctx, $value);
        }
        $count = \count($props);
        $classLen = \strlen($className);

        return 'O:'.$classLen.':"'.$className.'":'.$count.':{'.$body.'}';
    }

    private static function encodeSerializedValue(Context $ctx, Variable $value): string
    {
        return self::encodeSerializedScalar(self::exportForSerialize($ctx, $value));
    }

    /** Zend object wire with named exported properties (DateTime, DateInterval, #10710, #10692). */
    public static function encodeExportedPropertyBag(string $className, array $exportedProps): string
    {
        $body = '';
        foreach ($exportedProps as $name => $exported) {
            $body .= self::encodeSerializedScalar($name);
            $body .= self::encodeSerializedScalar($exported);
        }
        $count = \count($exportedProps);
        $classLen = \strlen($className);

        return 'O:'.$classLen.':"'.$className.'":'.$count.':{'.$body.'}';
    }

    /**
     * Zend object wire with integer property keys (ArrayObject, ArrayIterator; spl_array.c #10711).
     *
     * @param array<int, mixed> $exportedProps
     */
    public static function encodeIntegerKeyedPropertyBag(string $className, array $exportedProps): string
    {
        $body = '';
        ksort($exportedProps);
        foreach ($exportedProps as $key => $exported) {
            $body .= 'i:'.(int) $key.';';
            $body .= self::encodeSerializedScalar($exported);
        }
        $count = \count($exportedProps);
        $classLen = \strlen($className);

        return 'O:'.$classLen.':"'.$className.'":'.$count.':{'.$body.'}';
    }

    private static function encodeSerializedScalar(mixed $exported): string
    {
        return self::serializeExported($exported);
    }

    /** @param array<string, mixed> $data */
    private static function restoreObjectProperties(
        Context $ctx,
        ObjectEntry $entry,
        array $data,
        ?Frame $frame = null
    ): void {
        $vm = $ctx->runtime->vm();
        foreach ($data as $name => $raw) {
            $vm->assignUnserializeProperty($entry, (string) $name, VmJson::import($raw), $frame);
        }
    }

    /**
     * Resolve class for O:/C: unserialize after autoload + unserialize_callback_func (var_unserializer.c, #6564).
     */
    private static function resolveClassEntryForUnserialize(Context $ctx, string $className): ?ClassEntry
    {
        $lc = strtolower($className);
        if (isset($ctx->classes[$lc])) {
            return $ctx->classes[$lc];
        }
        $ctx->autoloadClass($className);
        if (isset($ctx->classes[$lc])) {
            return $ctx->classes[$lc];
        }
        $callback = VmIni::getUnserializeCallbackFunc();
        if ('' === $callback) {
            return null;
        }
        $classNameVar = new Variable();
        $classNameVar->string($className);
        $result = self::invokeNamedFunction($ctx, $callback, $classNameVar);
        if (!$result->resolveIndirect()->toBool()) {
            $ctx->errors->triggerError(
                "unserialize(): Function {$callback}() hasn't defined the class it was called for",
                ErrorReporter::E_WARNING
            );
        }
        if (isset($ctx->classes[$lc])) {
            return $ctx->classes[$lc];
        }

        return null;
    }

    /**
     * Zend __PHP_Incomplete_Class placeholder when class definition is missing (var_unserializer.c, #6564).
     *
     * @param array<string, mixed> $data
     */
    public static function instantiateIncompleteObject(
        Context $ctx,
        string $missingClassName,
        array $data
    ): Variable {
        $icClass = $ctx->classes['__php_incomplete_class'] ?? null;
        if (null === $icClass) {
            throw new \LogicException('__PHP_Incomplete_Class is not registered in this compiler build');
        }
        $entry = new ObjectEntry($icClass);
        $nameProp = $entry->allocateProperty('__PHP_Incomplete_Class_Name');
        $nameProp->string($missingClassName);
        self::restoreObjectProperties($ctx, $entry, $data, null);
        $recv = new Variable();
        $recv->object($entry);

        return $recv;
    }

    private static function invokeNamedFunction(Context $ctx, string $name, Variable ...$args): Variable
    {
        if (str_contains($name, '::')) {
            throw new \LogicException(
                'Static method unserialize callbacks are not supported in this compiler build'
            );
        }
        $lc = strtolower($name);
        if (!isset($ctx->functions[$lc])) {
            throw new \LogicException("Function {$name}() is not defined");
        }
        $fn = $ctx->functions[$lc];
        if ($fn instanceof FuncInternal) {
            $frame = new Frame($fn, null, null);
            $frame->vmContext = $ctx;
            $frame->calledArgs = $args;
            $out = new Variable();
            $frame->returnVar = $out;
            $fn->execute($frame);

            return $out;
        }
        if ($fn instanceof PhpFunc) {
            return $ctx->runtime->vm->invokePhpFunction($fn, ...$args);
        }

        throw new \LogicException("Function {$name}() is not callable");
    }

    private static function invokeSerialize(Context $ctx, ObjectEntry $entry): Variable
    {
        $method = self::resolveInstanceMethod($ctx, $entry->class, '__serialize');
        if ($method instanceof VmClassMethod) {
            $result = self::invokeBuiltinClassMethod($ctx, $method, $entry);
            if (Variable::TYPE_ARRAY !== $result->type) {
                self::throwSerializeMustReturnArray($entry->class->name);
            }

            return $result;
        }
        if (!$method instanceof PhpFunc) {
            throw new \LogicException(
                'Class '.$entry->class->name.'::__serialize() must be a user method in this compiler build'
            );
        }
        $recv = new Variable();
        $recv->object($entry);
        // Isolated stack: nested __serialize must not resume the caller frame mid-builtin (#25189, #11452).
        $result = $ctx->runtime->vm->invokePhpFunctionIsolated($method, $recv);
        if (Variable::TYPE_ARRAY !== $result->type) {
            self::throwSerializeMustReturnArray($entry->class->name);
        }

        return $result;
    }

    private static function invokeBuiltinClassMethod(
        Context $ctx,
        VmClassMethod $method,
        ObjectEntry $entry,
        Variable ...$extraArgs
    ): Variable {
        $recv = new Variable();
        $recv->object($entry);
        $frame = $method->getFrame($ctx, null);
        $frame->vmContext = $ctx;
        $frame->calledArgs = [$recv, ...$extraArgs];
        $out = new Variable();
        $frame->returnVar = $out;
        $method->execute($frame);

        return $out;
    }

    private static function invokeLegacySerializableSerialize(Context $ctx, ObjectEntry $entry): string
    {
        $method = $entry->class->methods['serialize'] ?? null;
        if (!$method instanceof PhpFunc) {
            throw new \LogicException(
                'Class '.$entry->class->name.'::serialize() must be a user method in this compiler build'
            );
        }
        $recv = new Variable();
        $recv->object($entry);
        // Isolated stack: nested Serializable::serialize must not resume the caller (#25189, #11452).
        $result = $ctx->runtime->vm->invokePhpFunctionIsolated($method, $recv);
        if (Variable::TYPE_STRING !== $result->type) {
            throw new \LogicException('Serializable::serialize() must return a string');
        }

        return $result->toString();
    }

    private static function implementsLegacySerializable(ClassEntry $class): bool
    {
        if (!\in_array('serializable', $class->interfaces, true)) {
            return false;
        }

        return isset($class->methods['serialize'], $class->methods['unserialize']);
    }

    /** php-src ext/standard/var.c — reject class@anonymous before __serialize/__sleep. */
    /** php-src ext/standard/var.c — reject anonymous classes before __serialize/__sleep (#28840). */
    private static function rejectAnonymousClassSerialization(ObjectEntry $entry): void
    {
        if (str_contains($entry->class->name, '@anonymous')) {
            $label = VmObjectDebugType::fromClassName($entry->class->name);
            throw new \Exception("Serialization of '{$label}' is not allowed");
        }
    }

    /**
     * Resolve an instance method including parent ClassEntry tables (builtin DOMNode::__sleep, #23073).
     */
    private static function resolveInstanceMethod(Context $ctx, ClassEntry $class, string $methodName): mixed
    {
        $methodLc = strtolower($methodName);
        $vm = $ctx->runtime->vm ?? null;
        if (null !== $vm) {
            try {
                [$decl] = $vm->resolveInstanceMethod($class, $methodLc);

                return $decl->methods[$methodLc] ?? null;
            } catch (\LogicException) {
                return null;
            }
        }
        $lcClass = strtolower($class->name);
        $visited = [];
        while (!isset($visited[$lcClass])) {
            $visited[$lcClass] = true;
            $entry = $ctx->classes[$lcClass] ?? null;
            if (null === $entry) {
                return null;
            }
            if (isset($entry->methods[$methodLc])) {
                return $entry->methods[$methodLc];
            }
            if (null === $entry->parentLc || '' === $entry->parentLc) {
                return null;
            }
            $lcClass = $entry->parentLc;
        }

        return null;
    }

    private static function hasInstanceMethod(Context $ctx, ClassEntry $class, string $methodName): bool
    {
        return null !== self::resolveInstanceMethod($ctx, $class, $methodName);
    }

    /** php-src zend_class_serialize() — __serialize() must return array (TypeError). */
    private static function throwSerializeMustReturnArray(string $className): never
    {
        throw new \TypeError($className.'::__serialize() must return an array');
    }
}

/**
 * Object / ISREF reference indices for serialize() wire format (php-src var.c php_add_var_hash).
 *
 * Root __serialize O: occupies stream index 1; nested object refs start at 2 (#11903).
 * Scalars and arrays advance nextIndex but are not stored — only objects and ISREF cells
 * participate in R:/r: lookup (#12825, #22653).
 */
final class VmSerializeRefState
{
    public int $nextIndex = 1;

    /** @var \SplObjectStorage<ObjectEntry, int> */
    public \SplObjectStorage $objectIndex;

    /** @var \SplObjectStorage<Variable, int> */
    public \SplObjectStorage $refCellIndex;

    public function __construct()
    {
        $this->objectIndex = new \SplObjectStorage();
        $this->refCellIndex = new \SplObjectStorage();
    }

    public function reserveRootSlot(): void
    {
        $this->nextIndex = 2;
    }

    public function assignObjectIndex(ObjectEntry $object): int
    {
        $index = $this->nextIndex++;
        $this->objectIndex[$object] = $index;

        return $index;
    }

    public function lookupObjectIndex(ObjectEntry $object): ?int
    {
        if (!$this->objectIndex->contains($object)) {
            return null;
        }

        return $this->objectIndex[$object];
    }

    /**
     * php_add_var_hash data->n += 1 for non-hashed visits (scalars/arrays).
     * Index is consumed for numbering only — not stored for R: lookup (#22653).
     */
    public function bumpIndex(): int
    {
        return $this->nextIndex++;
    }

    /** php-src ISREF zval identity — R: markers keyed by ref cell, not target (#12825). */
    public function assignRefCellIndex(Variable $refCell, ?int $index = null): int
    {
        if (null !== $index) {
            $this->refCellIndex[$refCell] = $index;

            return $index;
        }
        $index = $this->nextIndex++;
        $this->refCellIndex[$refCell] = $index;

        return $index;
    }

    public function lookupRefCellIndex(Variable $refCell): ?int
    {
        if (!$this->refCellIndex->contains($refCell)) {
            return null;
        }

        return $this->refCellIndex[$refCell];
    }
}

/** Marker for enum case values in VmSerialize::exportForSerialize() (#6131). */
final class VmSerializeEnumCaseRef
{
    public function __construct(
        public readonly string $className,
        public readonly string $caseName,
    ) {
    }
}
