<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin\Type;

use PHPCompiler\Config;
use PHPCompiler\JIT\Builtin\ReflectionMethodQueryConstructHelper;
use PHPCompiler\JIT\FiberHelper;
use PHPCompiler\JIT\GeneratorHelper;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Variable as VMVariable;

/**
 * External / zend builtin class registration for Object_ (#36387).
 *
 * Extracted from {@see Object_} so gen-0 spine gets a separate TU for the
 * large switch of builtin class layouts (stdClass, Throwable family, SPL,
 * DateTime, Reflection, …).
 *
 * Used via {@code use ObjectExternalClassRegister;} on {@see Object_}.
 *
 * No new C ABI. php-src: Zend/zend_API.c (zend_register_internal_class*),
 * Zend/zend_exceptions.c, ext/spl/spl_*.c, ext/date/php_date.c,
 * ext/reflection/php_reflection.c, Zend/zend_interfaces.c.
 */
trait ObjectExternalClassRegister
{
    private function registerExternalClass(string $lcname, string $displayName): void
    {
        $id = count($this->classes);
        $this->externalOnlyClassIds[$id] = true;
        $this->properties[$id] = [];
        $this->classConstants[$id] = [];
        $this->classes[$lcname] = $id;
        // Prefer Zend display spelling for builtins looked up as lowercase (#26885).
        if ('stdclass' === $lcname) {
            $displayName = 'stdClass';
            $this->allowsDynamicPropertiesClassIds[$id] = true;
        }
        // classIdToName must keep display spelling (stdClass, not stdclass) for
        // get_class()/get_debug_type()/::class (#23641, #26885). Lookup keys stay in $classes.
        $this->classIdToName[$id] = $displayName;
        if (self::externalClassRejectsDynamicProperties($lcname)) {
            $this->noDynamicPropertiesClassIds[$id] = true;
        }
        $this->ensureExternalClassConstants($id, $lcname);
        $this->seedExternalClassProperties($id, $lcname);
        $this->seedThrowableExternalClass($id, $lcname, $displayName);
        if ('reflectionattribute' === $lcname) {
            $this->defineProperty($id, 'name', Variable::TYPE_VALUE);
            $this->defineProperty($id, 'args', Variable::TYPE_HASHTABLE);
        }
        if ('reflectionclass' === $lcname) {
            // TYPE_VALUE: emitSetStringPropertyFromCstr stores heap __value__* boxes (#21551 / #34001).
            $this->defineProperty($id, 'name', Variable::TYPE_VALUE);
            // Thin user-script AOT must call __construct (not allocate-only) (#34001).
            $this->markHasConstructor($id);
            // Cast/(string) uses hasInstanceMethod(__tostring) (#34135).
            $this->defineMethodVisibility($id, '__tostring', \PHPCfg\Func::FLAG_PUBLIC, '__toString');
        }
        if ('reflectionobject' === $lcname) {
            $this->setClassParentName('ReflectionObject', 'ReflectionClass');
            // Zend public `$name` (inherited surface) + wrapped instance (#20098 / #34001).
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_CLASS_NAME, Variable::TYPE_VALUE);
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_OBJECT_TARGET, Variable::TYPE_VALUE);
            $this->markHasConstructor($id);
        }
        if ('reflectionmethod' === $lcname) {
            $this->setClassParentName('ReflectionMethod', 'ReflectionFunctionAbstract');
            // TYPE_VALUE: emitSetStringPropertyFromCstr stores heap __value__* boxes (#21551 / #33990).
            $this->defineProperty($id, 'class', Variable::TYPE_VALUE);
            $this->defineProperty($id, 'name', Variable::TYPE_VALUE);
            // Stamped at __construct for thin AOT query proxies (#34216).
            $this->defineProperty($id, ReflectionMethodQueryConstructHelper::PROP_COMPILER_METHOD_FLAGS, Variable::TYPE_NATIVE_LONG);
            $this->defineProperty($id, ReflectionMethodQueryConstructHelper::PROP_COMPILER_METHOD_PARAM_COUNT, Variable::TYPE_NATIVE_LONG);
            $this->defineProperty(
                $id,
                ReflectionMethodQueryConstructHelper::PROP_COMPILER_METHOD_REQUIRED_PARAM_COUNT,
                Variable::TYPE_NATIVE_LONG
            );
            // Thin user-script AOT must call __construct (not allocate-only) (#33990).
            $this->markHasConstructor($id);
        }
        if ('reflectionfunction' === $lcname) {
            $this->setClassParentName('ReflectionFunction', 'ReflectionFunctionAbstract');
            // Zend public `$name` (#22488).
            // TYPE_VALUE: emitSetStringPropertyFromCstr stores heap __value__* boxes (#21551 / #33993).
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_REFLECTION_FUNCTION_NAME, Variable::TYPE_VALUE);
            // Thin user-script AOT must call __construct (not allocate-only) (#33993 / #27315).
            $this->markHasConstructor($id);
        }
        if ('reflectionextension' === $lcname) {
            // Zend public `$name` (PROP_EXTENSION_NAME); getName / property fetch (#34003 / #34008).
            // TYPE_VALUE: emitSetStringPropertyFromCstr stores heap __value__* boxes (#21551).
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_EXTENSION_NAME, Variable::TYPE_VALUE);
            // Thin user-script AOT must call __construct (not allocate-only) (#34003).
            $this->markHasConstructor($id);
            // Cast/(string) uses hasInstanceMethod(__tostring) (#34181).
            $this->defineMethodVisibility($id, '__tostring', \PHPCfg\Func::FLAG_PUBLIC, '__toString');
        }
        if ('reflectionparameter' === $lcname) {
            // Public Zend surface: `$name` only; other slots are engine storage (#22528).
            // TYPE_VALUE: emitSetStringPropertyFromCstr stores heap __value__* boxes (#21551 / #33993).
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_PARAM_NAME, Variable::TYPE_VALUE);
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_PARAM_CLASS, Variable::TYPE_VALUE);
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_METHOD_NAME, Variable::TYPE_VALUE);
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_FUNC_NAME, Variable::TYPE_VALUE);
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_PARAM_INDEX, Variable::TYPE_NATIVE_LONG);
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_PARAM_POSITION, Variable::TYPE_NATIVE_LONG);
            // Thin user-script AOT must call __construct (not allocate-only) (#33993 / #27315).
            $this->markHasConstructor($id);
        }
        if ('reflectionproperty' === $lcname) {
            // Zend public surface: $name then $class (#22504).
            // TYPE_VALUE: emitSetStringPropertyFromCstr stores heap __value__* boxes (#21551 / #27315).
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_PROPERTY_NAME, Variable::TYPE_VALUE);
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_DECLARING_CLASS_NAME, Variable::TYPE_VALUE);
            // Thin user-script AOT must call __construct (not allocate-only) (#27315 / #27303 / #26772).
            $this->markHasConstructor($id);
        }
        if ('reflectionclassconstant' === $lcname) {
            // Zend public surface: $name then $class (#22503).
            // TYPE_VALUE + construct: same heap-box / allocate-only traps as ReflectionProperty (#33990).
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_REFLECTION_CLASS_CONSTANT_NAME, Variable::TYPE_VALUE);
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_REFLECTION_CLASS_CONSTANT_CLASS, Variable::TYPE_VALUE);
            $this->markHasConstructor($id);
        }
        if ('reflectionconstant' === $lcname) {
            // TYPE_VALUE: emitSetStringPropertyFromCstr stores heap __value__* boxes (#21551 / #27303).
            $this->defineProperty($id, 'name', Variable::TYPE_VALUE);
            $this->defineProperty($id, 'constant', Variable::TYPE_VALUE);
            // Thin user-script AOT must call __construct (not allocate-only) (#27303 / #26772).
            $this->markHasConstructor($id);
        }
        // PhpToken props / methods — ext/tokenizer/Module::jitInit seeder (#36204 / #27263).
        if ('reflectionenum' === $lcname) {
            // TYPE_VALUE: emitSetStringPropertyFromCstr stores heap __value__* boxes (#21551 / #27314).
            $this->defineProperty($id, 'name', Variable::TYPE_VALUE);
            // Thin user-script AOT must call __construct (not allocate-only) (#27314 / #27303 / #26772).
            $this->markHasConstructor($id);
        }
        if ('reflectionenumunitcase' === $lcname) {
            // Zend public surface: $name then $class (#22505; was internal-only enumClass).
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_CLASS_NAME, Variable::TYPE_STRING);
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_ENUM_CLASS_NAME, Variable::TYPE_STRING);
        }
        if ('reflectionenumbackedcase' === $lcname) {
            $this->setClassParentName('ReflectionEnumBackedCase', 'ReflectionEnumUnitCase');
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_CLASS_NAME, Variable::TYPE_STRING);
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_ENUM_CLASS_NAME, Variable::TYPE_STRING);
        }
        if ('reflectionnamedtype' === $lcname) {
            // TYPE_VALUE: emitSetStringPropertyFromCstr stores heap __value__* boxes (#27515).
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_TYPE_NAME, Variable::TYPE_VALUE);
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_TYPE_STRING, Variable::TYPE_VALUE);
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_TYPE_BUILTIN, Variable::TYPE_NATIVE_BOOL);
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_TYPE_ALLOWS_NULL, Variable::TYPE_NATIVE_BOOL);
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_TYPE_MEMBERS, Variable::TYPE_HASHTABLE);
            // Cast/(string) on getReturnType() — ReflectionType hint misses proxy (#28780).
            $this->defineMethodVisibility($id, '__tostring', \PHPCfg\Func::FLAG_PUBLIC, '__toString');
        }
        if ('reflectionuniontype' === $lcname) {
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_TYPE_STRING, Variable::TYPE_VALUE);
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_TYPE_ALLOWS_NULL, Variable::TYPE_NATIVE_BOOL);
            $this->defineProperty($id, \PHPCompiler\VM\ReflectionSupport::PROP_TYPE_MEMBERS, Variable::TYPE_HASHTABLE);
            $this->defineMethodVisibility($id, '__tostring', \PHPCfg\Func::FLAG_PUBLIC, '__toString');
        }
        // HashContext JIT handle slot must exist before allocate() (ext/hash/JitHashContext.php, #3357).
        // __hcKey / __hcHmac required for standalone AOT final + hash_copy (#23585, #27264) —
        // undeclared props auto-define as TYPE_STRING and reject the HMAC int64 store.
        if ('hashcontext' === $lcname) {
            $this->defineProperty($id, '__hcId', Variable::TYPE_NATIVE_LONG);
            $this->defineProperty($id, '__hcAlgo', Variable::TYPE_STRING);
            $this->defineProperty($id, '__hcBuf', Variable::TYPE_STRING);
            $this->defineProperty($id, '__hcKey', Variable::TYPE_STRING);
            $this->defineProperty($id, '__hcHmac', Variable::TYPE_NATIVE_LONG);
        }
        // DeflateContext / InflateContext thin-AOT buffer slots (#35885 leftover of #4656).
        if ('deflatecontext' === $lcname || 'inflatecontext' === $lcname) {
            $this->defineProperty($id, \PHPCompiler\ext\standard\ZlibIncrementalJitSupport::PROP_ENC, Variable::TYPE_NATIVE_LONG);
            $this->defineProperty($id, \PHPCompiler\ext\standard\ZlibIncrementalJitSupport::PROP_LEVEL, Variable::TYPE_NATIVE_LONG);
            $this->defineProperty($id, \PHPCompiler\ext\standard\ZlibIncrementalJitSupport::PROP_BUF, Variable::TYPE_STRING);
            $this->defineProperty($id, \PHPCompiler\ext\standard\ZlibIncrementalJitSupport::PROP_STATUS, Variable::TYPE_NATIVE_LONG);
            $this->defineProperty($id, \PHPCompiler\ext\standard\ZlibIncrementalJitSupport::PROP_READ_LEN, Variable::TYPE_NATIVE_LONG);
            $this->markFinalClass($lcname);
        }
        // ZipArchive stub props — ext/zip/Module::jitInit seeder (#36204 / #35002).
        // OpenSSL* PEM slots — ext/openssl/Module::jitInit seeder (#36204 / #34015).
        if ('phpcompiler\vm\context' === $lcname) {
            $this->defineProperty($id, 'runtime', Variable::TYPE_OBJECT);
            $this->defineProperty($id, 'errors', Variable::TYPE_OBJECT);
            $this->defineProperty($id, 'scriptStack', Variable::TYPE_OBJECT);
        }
        if ('phpcompiler\runtime' === $lcname) {
            $selfHostAot = Config::getenv('PHP_COMPILER_SELFHOST_AOT');
            $m5DriverHost = Config::getenv('PHP_COMPILER_M5_DRIVER_HOST');
            $m5Host = '1' === $m5DriverHost || 'true' === strtolower((string) $m5DriverHost);
            // SELFHOST_AOT normally keeps only `mode` — full Runtime property init segfaults
            // LLVM 9 when NestedJIT-lowering `new Runtime()` (#2600). M5 argv / gen-0 seed uses
            // C-floor initParsePipeline/initCompiler/initVmContext instead (#26756), so the
            // parse-spine slots must exist or propertyStore writes nowhere and parse SEGV on null.
            if (('1' === $selfHostAot || 'true' === strtolower((string) $selfHostAot)) && !$m5Host) {
                $this->defineProperty($id, 'mode', Variable::TYPE_NATIVE_LONG);
            } else {
                foreach (
                    [
                        'compiler',
                        'parser',
                        'preprocessor',
                        'postprocessor',
                        'detector',
                        'assignOpResolver',
                        'vmContext',
                        'vm',
                        'jitContext',
                        'jit',
                        'typeReconstructor',
                        // C-floor RuntimeInitParsePipeline also stores these annotators (#26756).
                        'confusableBuiltinTypeHintCheck',
                        'abstractEnumMarker',
                        'sealedClassAnnotator',
                        'staticClassAnnotator',
                    ] as $prop
                ) {
                    $this->defineProperty($id, $prop, Variable::TYPE_OBJECT);
                }
                $this->defineProperty($id, 'modules', Variable::TYPE_HASHTABLE);
                $this->defineProperty($id, 'mode', Variable::TYPE_NATIVE_LONG);
                // C-floor sets true so parse() skips prepare list-unpack SEGV (#26756).
                $this->defineProperty($id, 'm5ArgvIdentityParsePrepare', Variable::TYPE_NATIVE_BOOL);
            }
        }
        if ('closure' === $lcname) {
            // Invoke metadata for indirect holders (array elements, properties; issue #72).
            $this->defineProperty($id, '__closure_target', Variable::TYPE_STRING);
            $this->defineProperty($id, FiberHelper::TARGET_PROPERTY, Variable::TYPE_STRING);
            $this->defineProperty($id, \PHPCompiler\JIT\ClosureBindHelper::BOUND_THIS_PROPERTY, Variable::TYPE_OBJECT);
            $this->defineProperty($id, \PHPCompiler\JIT\ClosureBindHelper::BOUND_SCOPE_PROPERTY, Variable::TYPE_STRING);
            $this->defineProperty($id, \PHPCompiler\JIT\ClosureBindHelper::IS_STATIC_PROPERTY, Variable::TYPE_NATIVE_BOOL);
            $this->defineProperty($id, \PHPCompiler\JIT\ClosureBindHelper::IS_METHOD_PROPERTY, Variable::TYPE_NATIVE_BOOL);
            if (\PHPCompiler\CompilerVersion::supportsClosureGetCurrent()) {
                $this->defineMethodVisibility(
                    $id,
                    'getcurrent',
                    \PHPCfg\Func::FLAG_PUBLIC | \PHPCfg\Func::FLAG_STATIC
                );
            }
        }
        if ('fiber' === $lcname) {
            $this->defineProperty($id, FiberHelper::TARGET_PROPERTY, Variable::TYPE_STRING);
            $this->defineProperty($id, FiberHelper::STATE_PROPERTY, Variable::TYPE_NATIVE_LONG);
            foreach (['suspend', 'getcurrent'] as $fiberStaticMethod) {
                $this->defineMethodVisibility(
                    $id,
                    $fiberStaticMethod,
                    \PHPCfg\Func::FLAG_PUBLIC | \PHPCfg\Func::FLAG_STATIC
                );
            }
        }
        if ('generator' === $lcname) {
            $this->defineProperty($id, GeneratorHelper::TARGET_PROPERTY, Variable::TYPE_STRING);
            $this->defineProperty($id, GeneratorHelper::STATE_PROPERTY, Variable::TYPE_NATIVE_LONG);
            $this->ensureTraversableBuiltinInterfaces();
            $this->setClassInterfaces($displayName, ['Iterator']);
        }
        if ('dateinterval' === $lcname) {
            foreach (['y', 'm', 'd', 'h', 'i', 's', 'invert'] as $prop) {
                $this->defineProperty($id, $prop, Variable::TYPE_NATIVE_LONG);
            }
            $this->defineProperty($id, 'f', Variable::TYPE_VALUE);
            // php-src DateInterval::$days is int|false — VALUE slot holds either (#27309).
            $this->defineProperty($id, 'days', Variable::TYPE_VALUE);
            // Thin user-script AOT must call __construct (not allocate-only) (#26772).
            $this->markHasConstructor($id);
        }
        if ('datetimeimmutable' === $lcname || 'datetime' === $lcname) {
            $this->defineProperty($id, \PHPCompiler\VM\DateTimeSupport::TS_PROPERTY, Variable::TYPE_NATIVE_LONG);
            $this->defineProperty($id, \PHPCompiler\VM\DateTimeSupport::MICROSECOND_PROPERTY, Variable::TYPE_NATIVE_LONG);
            $this->defineProperty($id, \PHPCompiler\VM\DateTimeSupport::TZ_PROPERTY, Variable::TYPE_STRING);
            // Thin user-script AOT must call __construct (not allocate-only) (#26772).
            $this->markHasConstructor($id);
        }
        if ('pdo' === $lcname) {
            // Thin user-script AOT must call __construct (not allocate-only) so missing
            // drivers throw PDOException instead of silent success (#27619).
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            $pubStatic = $pub | \PHPCfg\Func::FLAG_STATIC;
            $this->defineMethodVisibility($id, '__construct', $pub);
            $this->defineMethodVisibility($id, 'getavailabledrivers', $pubStatic, 'getAvailableDrivers');
            $this->defineMethodVisibility($id, 'quote', $pub);
        }
        if ('datetimezone' === $lcname) {
            $this->defineProperty($id, \PHPCompiler\VM\DateTimeSupport::TZ_NAME_PROPERTY, Variable::TYPE_STRING);
            // Thin user-script AOT must call __construct (not allocate-only) (#26772).
            $this->markHasConstructor($id);
        }
        if ('dateperiod' === $lcname) {
            $this->ensureTraversableBuiltinInterfaces();
            // php-src date.stub.php — IteratorAggregate only (#22263, #22608).
            $this->setClassInterfaces($displayName, ['IteratorAggregate']);
            // php-src REGISTER_DATEPERIOD_CLASS_CONST_LONG (#20071, ext/date/php_date.c).
            $this->seedExternalClassConstants($id, [
                'exclude_start_date' => \PHPCompiler\VM\DatePeriodSupport::OPTION_EXCLUDE_START_DATE,
                'include_end_date' => \PHPCompiler\VM\DatePeriodSupport::OPTION_INCLUDE_END_DATE,
            ]);
            // php-src @readonly write handlers — assign reject only; unset + isReadOnly Zend 8.2 (#26154).
            foreach (['start', 'current', 'end', 'interval'] as $prop) {
                $this->defineProperty($id, $prop, Variable::TYPE_OBJECT);
                $this->markPropertyWriteReject($id, $prop);
            }
            foreach (['recurrences'] as $prop) {
                $this->defineProperty($id, $prop, Variable::TYPE_NATIVE_LONG);
                $this->markPropertyWriteReject($id, $prop);
            }
            foreach (['include_start_date', 'include_end_date'] as $prop) {
                $this->defineProperty($id, $prop, Variable::TYPE_NATIVE_BOOL);
                $this->markPropertyWriteReject($id, $prop);
            }
            foreach (['__dp_iter_key'] as $prop) {
                $this->defineProperty($id, $prop, Variable::TYPE_NATIVE_LONG);
            }
            $this->defineProperty($id, '__dp_iter_started', Variable::TYPE_NATIVE_BOOL);
            $pubStatic = \PHPCfg\Func::FLAG_PUBLIC | \PHPCfg\Func::FLAG_STATIC;
            if (\PHPCompiler\CompilerVersion::supportsDatePeriodCreateFromISO8601String()) {
                $this->defineMethodVisibility($id, 'createfromiso8601string', $pubStatic);
            }
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach (['getiterator', 'getstartdate', 'getenddate', 'getdateinterval', 'getrecurrences'] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
            // Thin user-script AOT must call __construct (not allocate-only) (#26772).
            $this->markHasConstructor($id);
        }
        // DOMNode / DOMElement / DOMDocument layout — ext/dom/Module::jitInit seeders (#36204).
        if ('domattr' === $lcname) {
            foreach ([
                'nodeName', 'name', 'value', 'nodeValue',
                'namespaceURI', 'localName', 'prefix',
            ] as $prop) {
                $this->defineProperty($id, $prop, Variable::TYPE_STRING);
            }
            $this->defineProperty($id, 'ownerElement', Variable::TYPE_VALUE);
            $this->defineProperty($id, 'nodeType', Variable::TYPE_NATIVE_LONG);
            $this->defineMethodVisibility($id, 'isid', \PHPCfg\Func::FLAG_PUBLIC);
        }
        if ('dom\\attr' === $lcname) {
            // Living Dom\Attr for thin AOT method_exists / property layout (#27108).
            foreach ([
                'nodeName', 'name', 'value', 'nodeValue',
                'namespaceURI', 'localName', 'prefix',
            ] as $prop) {
                $this->defineProperty($id, $prop, Variable::TYPE_STRING);
            }
            $this->defineProperty($id, 'ownerElement', Variable::TYPE_VALUE);
            $this->defineProperty($id, 'nodeType', Variable::TYPE_NATIVE_LONG);
            $this->defineMethodVisibility($id, 'rename', \PHPCfg\Func::FLAG_PUBLIC);
        }
        if ('dom\\xmldocument' === $lcname) {
            $this->defineProperty($id, 'documentElement', Variable::TYPE_OBJECT);
            $this->defineMethodVisibility($id, 'createfromstring', \PHPCfg\Func::FLAG_PUBLIC | \PHPCfg\Func::FLAG_STATIC);
            $this->defineMethodVisibility($id, 'createfromfile', \PHPCfg\Func::FLAG_PUBLIC | \PHPCfg\Func::FLAG_STATIC);
            $this->defineMethodVisibility($id, 'createattribute', \PHPCfg\Func::FLAG_PUBLIC);
        }
        if ('dom\\htmldocument' === $lcname) {
            // Living HTMLDocument body/documentElement slots for thin AOT createFromString (#27300).
            $this->defineProperty($id, 'documentElement', Variable::TYPE_OBJECT);
            $this->defineProperty($id, 'body', Variable::TYPE_OBJECT);
            $this->defineMethodVisibility($id, 'createfromstring', \PHPCfg\Func::FLAG_PUBLIC | \PHPCfg\Func::FLAG_STATIC);
            $this->defineMethodVisibility($id, 'createfromfile', \PHPCfg\Func::FLAG_PUBLIC | \PHPCfg\Func::FLAG_STATIC);
        }
        if ('splobjectstorage' === $lcname) {
            $this->splObjectStorageClassId = $id;
            // Slot 0 must stay `__spl_ht` for splBackingHashtable (#26787 / #28707).
            $this->defineProperty($id, \PHPCompiler\VM\SplObjectStorageJitHelper::PROP_HT, Variable::TYPE_HASHTABLE);
            $this->defineProperty($id, \PHPCompiler\VM\SplObjectStorageJitHelper::PROP_ITER_POS, Variable::TYPE_NATIVE_LONG);
            // php-src ext/spl/spl_observer.stub.php — Countable + Iterator + Serializable + ArrayAccess.
            // Thin AOT TYPE_VALUE dim needs ArrayAccess so object keys avoid Illegal offset (#26787 / #24681).
            $this->ensureZendBuiltinInterfaces();
            $this->setClassInterfaces($displayName, [
                'Countable',
                'Iterator',
                'Traversable',
                'Serializable',
                'ArrayAccess',
            ]);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach ([
                '__construct', 'attach', 'detach', 'contains', 'addall', 'removeall', 'removeallexcept',
                'gethash', 'count', 'rewind', 'valid', 'key', 'current', 'next',
                'offsetset', 'offsetget', 'offsetexists', 'offsetunset',
                'getinfo', 'setinfo',
            ] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        if ('arrayiterator' === $lcname || 'recursivearrayiterator' === $lcname) {
            // Thin user-script AOT foreach via `__spl_ht` packed walk (#26783, #26775).
            // php-src ext/spl/spl_array.stub.php — SeekableIterator + ArrayAccess + Serializable + Countable.
            $this->ensureZendBuiltinInterfaces();
            $ifaces = [
                'SeekableIterator',
                'ArrayAccess',
                'Serializable',
                'Countable',
            ];
            if ('recursivearrayiterator' === $lcname) {
                // Zend rematerializes Countable-first + RecursiveIterator (#25796).
                $this->markInterfaceClass('RecursiveIterator');
                $this->setInterfaceExtends('RecursiveIterator', ['Iterator', 'Traversable']);
                $ifaces = [
                    'Countable',
                    'Serializable',
                    'ArrayAccess',
                    'Iterator',
                    'Traversable',
                    'SeekableIterator',
                    'RecursiveIterator',
                ];
            }
            $this->setClassInterfaces($displayName, $ifaces);
            $this->defineProperty($id, '__spl_ht', Variable::TYPE_HASHTABLE);
            $this->defineProperty($id, \PHPCompiler\VM\ArrayObjectJitHelper::PROP_FLAGS, Variable::TYPE_NATIVE_LONG);
            $constants = [
                'STD_PROP_LIST' => 1,
                'ARRAY_AS_PROPS' => 2,
            ];
            if ('recursivearrayiterator' === $lcname) {
                $constants['CHILD_ARRAYS_ONLY'] = 4;
            }
            $this->seedExternalClassConstants($id, $constants);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach ([
                '__construct', 'rewind', 'valid', 'current', 'key', 'next', 'seek',
                'count', 'append', 'getarraycopy', 'getflags', 'setflags',
                'offsetget', 'offsetset', 'offsetexists', 'offsetunset',
                'haschildren', 'getchildren',
            ] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        if ('arrayobject' === $lcname) {
            // Thin AOT: `__spl_ht` + IteratorAggregate foreach / ArrayAccess (#26823, #27567).
            // php-src ext/spl/spl_array.stub.php — IteratorAggregate, ArrayAccess, Serializable, Countable.
            $this->ensureZendBuiltinInterfaces();
            $this->setClassInterfaces($displayName, [
                'IteratorAggregate',
                'ArrayAccess',
                'Serializable',
                'Countable',
            ]);
            $this->defineProperty($id, '__spl_ht', Variable::TYPE_HASHTABLE);
            $this->defineProperty($id, \PHPCompiler\VM\ArrayObjectJitHelper::PROP_FLAGS, Variable::TYPE_NATIVE_LONG);
            $this->defineProperty($id, \PHPCompiler\VM\ArrayObjectJitHelper::PROP_ITERATOR_CLASS, Variable::TYPE_STRING);
            $this->defineProperty($id, \PHPCompiler\VM\ArrayObjectJitHelper::PROP_ITERATOR_CLASS_ID, Variable::TYPE_NATIVE_LONG);
            $this->seedExternalClassConstants($id, [
                'STD_PROP_LIST' => 1,
                'ARRAY_AS_PROPS' => 2,
            ]);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach ([
                '__construct', 'count', 'getarraycopy', 'getiterator', 'getiteratorclass', 'setiteratorclass',
                'getflags', 'setflags', 'append', 'exchangearray',
                'offsetget', 'offsetset', 'offsetexists', 'offsetunset',
            ] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        // XMLReader layout + constants — ext/xmlreader/Module::jitInit seeder (#36204 / #27299).
        if (
            'limititerator' === $lcname
            || 'appenditerator' === $lcname
            || 'regexiterator' === $lcname
            || 'callbackfilteriterator' === $lcname
            || 'cachingiterator' === $lcname
        ) {
            // Thin AOT: snapshot / filter into `__spl_ht` at construct (#26825, #27259, #27421).
            // php-src ext/spl/spl_iterators.stub.php — OuterIterator + Iterator.
            // markHasConstructor requires isVoidJitConstructCall recognition or
            // constructed stays 0 and get_class / HT reads abort (#26825).
            $this->ensureZendBuiltinInterfaces();
            $this->markInterfaceClass('OuterIterator');
            $this->setInterfaceExtends('OuterIterator', ['Iterator', 'Traversable']);
            // Iterator-first rematerialized order (#25798).
            $ifaces = [
                'Iterator',
                'Traversable',
                'OuterIterator',
            ];
            if ('cachingiterator' === $lcname) {
                $ifaces = [
                    'Stringable',
                    'Iterator',
                    'Traversable',
                    'OuterIterator',
                    'ArrayAccess',
                    'Countable',
                ];
            }
            $this->setClassInterfaces($displayName, $ifaces);
            $this->defineProperty($id, '__spl_ht', Variable::TYPE_HASHTABLE);
            if ('limititerator' === $lcname) {
                $this->defineProperty($id, \PHPCompiler\VM\LimitIteratorJitHelper::PROP_OFFSET, Variable::TYPE_NATIVE_LONG);
                $this->defineProperty($id, \PHPCompiler\VM\LimitIteratorJitHelper::PROP_LIMIT, Variable::TYPE_NATIVE_LONG);
                $this->defineProperty($id, \PHPCompiler\VM\LimitIteratorJitHelper::PROP_SRC_NUM, Variable::TYPE_NATIVE_LONG);
            }
            if ('appenditerator' === $lcname) {
                // Parallel original keys — spreadInto renumbers packed indices (#27312).
                $this->defineProperty($id, \PHPCompiler\JIT\Call\AppendIteratorMethod::PROP_KEYS, Variable::TYPE_HASHTABLE);
            }
            if ('cachingiterator' === $lcname) {
                $this->defineProperty($id, \PHPCompiler\JIT\Call\CachingIteratorConstruct::PROP_CACHE, Variable::TYPE_HASHTABLE);
                $this->defineProperty($id, \PHPCompiler\VM\CachingIteratorJitHelper::PROP_FLAGS, Variable::TYPE_NATIVE_LONG);
                $this->seedExternalClassConstants($id, [
                    'CALL_TOSTRING' => 1,
                    'TOSTRING_USE_KEY' => 2,
                    'TOSTRING_USE_CURRENT' => 4,
                    'TOSTRING_USE_INNER' => 8,
                    'CATCH_GET_CHILD' => 16,
                    'FULL_CACHE' => 0x100,
                ]);
            }
            if ('regexiterator' === $lcname) {
                $this->seedExternalClassConstants($id, [
                    'USE_KEY' => 1,
                    'INVERT_MATCH' => 2,
                    'MATCH' => 0,
                    'GET_MATCH' => 1,
                    'ALL_MATCHES' => 2,
                    'SPLIT' => 3,
                    'REPLACE' => 4,
                ]);
            }
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            $methods = [
                '__construct', 'rewind', 'valid', 'current', 'key', 'next', 'getinneriterator',
            ];
            if ('limititerator' === $lcname) {
                $methods[] = 'seek';
                $methods[] = 'getposition';
            } elseif ('appenditerator' === $lcname) {
                $methods[] = 'append';
                $methods[] = 'getiteratorindex';
                $methods[] = 'getarrayiterator';
            } elseif ('cachingiterator' === $lcname) {
                $methods[] = 'getcache';
                $methods[] = 'getflags';
                $methods[] = 'setflags';
                $methods[] = 'count';
                $methods[] = 'hasnext';
            } elseif ('regexiterator' === $lcname || 'callbackfilteriterator' === $lcname) {
                $methods[] = 'accept';
            }
            foreach ($methods as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        if ('filteriterator' === $lcname) {
            // Thin AOT: snapshot `__spl_ht` + Iterator protocol; accept() filters (#27565).
            // Not SplOuterIteratorHt — foreach must call rewind/next so accept runs.
            // php-src ext/spl/spl_iterators.c — spl_FilterIterator / dual_it_fetch.
            $this->ensureZendBuiltinInterfaces();
            $this->markInterfaceClass('OuterIterator');
            $this->setInterfaceExtends('OuterIterator', ['Iterator', 'Traversable']);
            $this->setClassInterfaces($displayName, [
                'Iterator',
                'Traversable',
                'OuterIterator',
            ]);
            $this->defineProperty($id, \PHPCompiler\VM\FilterIteratorJitHelper::PROP_HT, Variable::TYPE_HASHTABLE);
            $this->defineProperty($id, \PHPCompiler\VM\FilterIteratorJitHelper::PROP_POS, Variable::TYPE_NATIVE_LONG);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            $prot = \PHPCfg\Func::FLAG_PROTECTED;
            foreach ([
                '__construct', 'rewind', 'valid', 'current', 'key', 'next', 'getinneriterator',
            ] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
            $this->defineMethodVisibility($id, 'accept', $prot);
        }
        if (
            'parentiterator' === $lcname
            || 'multipleiterator' === $lcname
            || 'recursivetreeiterator' === $lcname
        ) {
            // Thin AOT: snapshot / filter into `__spl_ht` at construct (#27584).
            // php-src ext/spl/spl_iterators.c — ParentIterator / MultipleIterator / RecursiveTreeIterator.
            $this->ensureZendBuiltinInterfaces();
            if ('multipleiterator' !== $lcname) {
                $this->markInterfaceClass('OuterIterator');
                $this->setInterfaceExtends('OuterIterator', ['Iterator', 'Traversable']);
                $ifaces = ['Iterator', 'Traversable', 'OuterIterator'];
                if ('parentiterator' === $lcname) {
                    $this->markInterfaceClass('RecursiveIterator');
                    $this->setInterfaceExtends('RecursiveIterator', ['Iterator', 'Traversable']);
                    $ifaces[] = 'RecursiveIterator';
                }
                $this->setClassInterfaces($displayName, $ifaces);
            } else {
                $this->setClassInterfaces($displayName, ['Iterator', 'Traversable']);
            }
            $this->defineProperty($id, '__spl_ht', Variable::TYPE_HASHTABLE);
            if ('multipleiterator' === $lcname) {
                $this->defineProperty(
                    $id,
                    \PHPCompiler\JIT\Call\MultipleIteratorMethod::PROP_ATTACHED,
                    Variable::TYPE_NATIVE_LONG
                );
                $this->seedExternalClassConstants($id, [
                    'MIT_NEED_ANY' => 0,
                    'MIT_NEED_ALL' => 1,
                    'MIT_KEYS_NUMERIC' => 0,
                    'MIT_KEYS_ASSOC' => 2,
                ]);
            }
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            $methods = ['__construct'];
            if ('multipleiterator' === $lcname) {
                $methods[] = 'attachiterator';
            }
            foreach ($methods as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        if ('norewinditerator' === $lcname || 'infiniteiterator' === $lcname) {
            // Thin AOT: snapshot `__spl_ht` + Iterator protocol via `__spl_iter_pos` (#27583).
            // Not SplOuterIteratorHt — foreach must call rewind (NoRewind = no-op).
            // php-src ext/spl/spl_iterators.c — spl_norewind_it_* / InfiniteIterator.
            $this->ensureZendBuiltinInterfaces();
            $this->markInterfaceClass('OuterIterator');
            $this->setInterfaceExtends('OuterIterator', ['Iterator', 'Traversable']);
            $this->setClassInterfaces($displayName, [
                'Iterator',
                'Traversable',
                'OuterIterator',
            ]);
            // Slot 0 must be `__spl_ht` for splBackingHashtable (#26783).
            $this->defineProperty($id, \PHPCompiler\VM\SplHtPosIteratorJitHelper::PROP_HT, Variable::TYPE_HASHTABLE);
            $this->defineProperty($id, \PHPCompiler\VM\SplHtPosIteratorJitHelper::PROP_POS, Variable::TYPE_NATIVE_LONG);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach ([
                '__construct', 'rewind', 'valid', 'current', 'key', 'next', 'getinneriterator',
            ] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        if ('emptyiterator' === $lcname) {
            // Thin AOT: Iterator protocol; current/key throw BadMethodCallException (#27582).
            // php-src ext/spl/spl_iterators.c — empty iterator.
            $this->ensureZendBuiltinInterfaces();
            $this->setClassInterfaces($displayName, [
                'Iterator',
                'Traversable',
            ]);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach (['__construct', 'rewind', 'valid', 'current', 'key', 'next'] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        if ('directory' === $lcname) {
            // Thin AOT: path + entry snapshot for dir() factory (#30757).
            // php-src ext/standard/dir.c — Directory class (path / read / rewind / close).
            $this->defineProperty($id, \PHPCompiler\VM\DirectoryJitHelper::PROP_PATH, Variable::TYPE_STRING);
            $this->defineProperty($id, \PHPCompiler\VM\DirectoryJitHelper::PROP_HT, Variable::TYPE_HASHTABLE);
            $this->defineProperty($id, \PHPCompiler\VM\DirectoryJitHelper::PROP_POS, Variable::TYPE_NATIVE_LONG);
            $this->defineProperty($id, \PHPCompiler\VM\DirectoryJitHelper::PROP_CLOSED, Variable::TYPE_NATIVE_LONG);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach (['__construct', 'read', 'rewind', 'close'] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        if ('splfileinfo' === $lcname) {
            // Thin AOT: pathname/filename for getFilename (#27289 / #27422).
            $this->ensureTraversableBuiltinInterfaces();
            $this->setClassInterfaces($displayName, ['Stringable']);
            $this->defineProperty($id, \PHPCompiler\VM\DirectoryIteratorJitHelper::PROP_FILENAME, Variable::TYPE_STRING);
            $this->defineProperty($id, \PHPCompiler\VM\DirectoryIteratorJitHelper::PROP_PATH, Variable::TYPE_STRING);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach ([
                '__construct', 'getfilename', 'getpathname', 'getpath', 'getbasename',
                'getextension', 'getsize', 'gettype', 'getrealpath', 'getmtime', 'getatime', 'getctime',
                'getperms', 'getowner', 'getgroup', 'getinode',
                '__tostring', 'isfile', 'isdir',
                'islink', 'getlinktarget', 'isreadable', 'iswritable', 'isexecutable',
                'getfileinfo', 'getpathinfo', 'openfile',
            ] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        if ('splfileobject' === $lcname) {
            // Thin AOT: line snapshot `__spl_ht` + foreach walk (#28709).
            // php-src ext/spl/spl_directory.c — SplFileObject / fgets iterator.
            $this->ensureZendBuiltinInterfaces();
            $this->markInterfaceClass('RecursiveIterator');
            $this->setInterfaceExtends('RecursiveIterator', ['Iterator', 'Traversable']);
            $this->markInterfaceClass('SeekableIterator');
            $this->setInterfaceExtends('SeekableIterator', ['Iterator', 'Traversable']);
            $this->setClassInterfaces($displayName, [
                'Stringable',
                'RecursiveIterator',
                'SeekableIterator',
                'Traversable',
                'Iterator',
            ]);
            // Slot 0 must be `__spl_ht` for splBackingHashtable (#26783).
            $this->defineProperty($id, \PHPCompiler\VM\SplFileObjectJitHelper::PROP_HT, Variable::TYPE_HASHTABLE);
            $this->defineProperty($id, \PHPCompiler\VM\SplFileObjectJitHelper::PROP_PATH, Variable::TYPE_STRING);
            // Live stream handle for fgets/fwrite/eof (#33318).
            $this->defineProperty($id, \PHPCompiler\VM\SplFileObjectJitHelper::PROP_FD, Variable::TYPE_NATIVE_LONG);
            // Iterator state / EOF latch (#33319).
            $this->defineProperty($id, \PHPCompiler\VM\SplFileObjectJitHelper::PROP_LINE, Variable::TYPE_NATIVE_LONG);
            $this->defineProperty($id, \PHPCompiler\VM\SplFileObjectJitHelper::PROP_HAS, Variable::TYPE_NATIVE_LONG);
            $this->defineProperty($id, \PHPCompiler\VM\SplFileObjectJitHelper::PROP_AT_EOF, Variable::TYPE_NATIVE_LONG);
            $this->defineProperty($id, \PHPCompiler\VM\SplFileObjectJitHelper::PROP_CUR_LINE, Variable::TYPE_STRING);
            // Flags for setFlags/getFlags (#33368).
            $this->defineProperty($id, \PHPCompiler\VM\SplFileObjectJitHelper::PROP_FLAGS, Variable::TYPE_NATIVE_LONG);
            // max_line_len for setMaxLineLen/getMaxLineLen (#33377).
            $this->defineProperty($id, \PHPCompiler\VM\SplFileObjectJitHelper::PROP_MAX_LINE_LEN, Variable::TYPE_NATIVE_LONG);
            // CSV control trio for setCsvControl/getCsvControl (#33371).
            $this->defineProperty($id, \PHPCompiler\VM\SplFileObjectJitHelper::PROP_CSV_SEP, Variable::TYPE_STRING);
            $this->defineProperty($id, \PHPCompiler\VM\SplFileObjectJitHelper::PROP_CSV_ENC, Variable::TYPE_STRING);
            $this->defineProperty($id, \PHPCompiler\VM\SplFileObjectJitHelper::PROP_CSV_ESC, Variable::TYPE_STRING);
            // SplFileInfo path props for inherited isFile/getSize/… (#33313).
            $this->defineProperty($id, \PHPCompiler\VM\DirectoryIteratorJitHelper::PROP_PATH, Variable::TYPE_STRING);
            $this->defineProperty($id, \PHPCompiler\VM\DirectoryIteratorJitHelper::PROP_FILENAME, Variable::TYPE_STRING);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach ([
                '__construct', 'rewind', 'valid', 'current', 'key', 'next', 'seek',
                'fgets', 'fwrite', 'eof', 'getcurrentline', 'fread', 'fgetc',
                'ftell', 'flock', 'fstat', 'ftruncate', 'fflush', 'fpassthru', 'fseek',
                'fputcsv', 'fgetcsv', 'fscanf', 'setflags', 'getflags', 'setmaxlinelen', 'getmaxlinelen',
                'setcsvcontrol', 'getcsvcontrol',
                'getfilename', 'getpathname', 'getpath', '__tostring',
                'getsize', 'getrealpath',
                'getmtime', 'getatime', 'getctime', 'getperms', 'getowner', 'getgroup', 'getinode',
                'isfile', 'isdir', 'islink', 'getlinktarget', 'isreadable', 'iswritable', 'isexecutable',
                'getextension', 'getbasename', 'gettype',
                'getfileinfo', 'getpathinfo', 'openfile',
            ] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        if ('spltempfileobject' === $lcname) {
            // Thin AOT: subclass of SplFileObject on php://temp (#33431 / #12891).
            // php-src ext/spl/spl_directory.c — SplTempFileObject.
            $this->lookup('SplFileObject');
            $this->setClassParentName($displayName, 'SplFileObject');
            $this->inheritParentInstanceProperties($id, 'splfileobject');
            $this->inheritMethodVisibilityFromParent($id, $lcname);
            $this->ensureZendBuiltinInterfaces();
            $this->markInterfaceClass('RecursiveIterator');
            $this->setInterfaceExtends('RecursiveIterator', ['Iterator', 'Traversable']);
            $this->markInterfaceClass('SeekableIterator');
            $this->setInterfaceExtends('SeekableIterator', ['Iterator', 'Traversable']);
            $this->setClassInterfaces($displayName, [
                'Stringable',
                'RecursiveIterator',
                'SeekableIterator',
                'Traversable',
                'Iterator',
            ]);
            $this->markHasConstructor($id);
            $this->defineMethodVisibility($id, '__construct', \PHPCfg\Func::FLAG_PUBLIC);
        }
        if ('directoryiterator' === $lcname || 'filesystemiterator' === $lcname || 'globiterator' === $lcname) {
            // Thin AOT: snapshot `__spl_ht` + Iterator; current() returns $this (#27289 / #27422).
            // php-src ext/spl/spl_directory.c — DirectoryIterator / FilesystemIterator / GlobIterator.
            $this->ensureZendBuiltinInterfaces();
            $this->markInterfaceClass('SeekableIterator');
            $this->setInterfaceExtends('SeekableIterator', ['Iterator', 'Traversable']);
            $ifaces = [
                'Stringable',
                'SeekableIterator',
                'Traversable',
                'Iterator',
            ];
            if ('globiterator' === $lcname) {
                $ifaces[] = 'Countable';
            }
            $this->setClassInterfaces($displayName, $ifaces);
            // Slot 0 must be `__spl_ht` for splBackingHashtable (#26783).
            $this->defineProperty($id, \PHPCompiler\VM\DirectoryIteratorJitHelper::PROP_HT, Variable::TYPE_HASHTABLE);
            $this->defineProperty($id, \PHPCompiler\VM\DirectoryIteratorJitHelper::PROP_POS, Variable::TYPE_NATIVE_LONG);
            $this->defineProperty($id, \PHPCompiler\VM\DirectoryIteratorJitHelper::PROP_FILENAME, Variable::TYPE_STRING);
            $this->defineProperty($id, \PHPCompiler\VM\DirectoryIteratorJitHelper::PROP_PATH, Variable::TYPE_STRING);
            $this->defineProperty($id, \PHPCompiler\VM\DirectoryIteratorJitHelper::PROP_FLAGS, Variable::TYPE_NATIVE_LONG);
            if ('filesystemiterator' === $lcname || 'globiterator' === $lcname) {
                $this->seedExternalClassConstants($id, [
                    'CURRENT_AS_PATHNAME' => 32,
                    'CURRENT_AS_FILEINFO' => 0,
                    'CURRENT_AS_SELF' => 16,
                    'CURRENT_MODE_MASK' => 0x000000F0,
                    'KEY_AS_PATHNAME' => 0,
                    'KEY_AS_FILENAME' => 256,
                    'KEY_MODE_MASK' => 0x00000F00,
                    'NEW_CURRENT_AND_KEY' => 256,
                    'SKIP_DOTS' => 4096,
                    'UNIX_PATHS' => 8192,
                    'FOLLOW_SYMLINKS' => 0x00004000,
                    'OTHER_MODE_MASK' => 0x00007000,
                ]);
            }
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            $methods = [
                '__construct', 'rewind', 'valid', 'current', 'key', 'next', 'seek',
                'isdot', 'getfilename', 'getpathname', 'getpath', 'getsize',
                'getextension', 'getbasename', 'gettype', 'getrealpath',
                'getmtime', 'getatime', 'getctime', 'getperms', 'getowner', 'getgroup', 'getinode',
                '__tostring',
                'isfile', 'isdir', 'islink', 'getlinktarget', 'isreadable', 'iswritable', 'isexecutable',
                'getfileinfo', 'getpathinfo',
            ];
            if ('filesystemiterator' === $lcname) {
                $methods = array_merge($methods, ['getflags', 'setflags']);
            }
            if ('globiterator' === $lcname) {
                $methods = array_merge($methods, ['count', 'getflags', 'setflags']);
            }
            foreach ($methods as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        if ('recursivedirectoryiterator' === $lcname) {
            // Thin AOT: subclass of FilesystemIterator + RecursiveIterator (#34624).
            // php-src ext/spl/spl_directory.c — RecursiveDirectoryIterator.
            $this->lookup('FilesystemIterator');
            $this->setClassParentName($displayName, 'FilesystemIterator');
            $this->inheritParentInstanceProperties($id, 'filesystemiterator');
            $this->inheritMethodVisibilityFromParent($id, $lcname);
            $this->ensureZendBuiltinInterfaces();
            $this->markInterfaceClass('RecursiveIterator');
            $this->setInterfaceExtends('RecursiveIterator', ['Iterator', 'Traversable']);
            $this->markInterfaceClass('SeekableIterator');
            $this->setInterfaceExtends('SeekableIterator', ['Iterator', 'Traversable']);
            $this->setClassInterfaces($displayName, [
                'Stringable',
                'RecursiveIterator',
                'SeekableIterator',
                'Traversable',
                'Iterator',
            ]);
            $this->seedExternalClassConstants($id, [
                'CURRENT_AS_PATHNAME' => 32,
                'CURRENT_AS_FILEINFO' => 0,
                'CURRENT_AS_SELF' => 16,
                'CURRENT_MODE_MASK' => 0x000000F0,
                'KEY_AS_PATHNAME' => 0,
                'KEY_AS_FILENAME' => 256,
                'KEY_MODE_MASK' => 0x00000F00,
                'NEW_CURRENT_AND_KEY' => 256,
                'SKIP_DOTS' => 4096,
                'UNIX_PATHS' => 8192,
                'FOLLOW_SYMLINKS' => 0x00004000,
                'OTHER_MODE_MASK' => 0x00007000,
            ]);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach ([
                '__construct', 'rewind', 'valid', 'current', 'key', 'next', 'seek',
                'isdot', 'getfilename', 'getpathname', 'getpath', 'getsize',
                'getextension', 'getbasename', 'gettype', 'getrealpath',
                'getmtime', 'getatime', 'getctime', 'getperms', 'getowner', 'getgroup', 'getinode',
                '__tostring',
                'isfile', 'isdir', 'islink', 'getlinktarget', 'isreadable', 'iswritable', 'isexecutable',
                'getfileinfo', 'getpathinfo', 'openfile',
                'getflags', 'setflags',
                'haschildren', 'getchildren', 'getsubpath', 'getsubpathname',
            ] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        if ('recursiveiteratoriterator' === $lcname) {
            // Thin AOT: LEAVES_ONLY flatten into `__spl_ht` at construct (#26775).
            // php-src ext/spl/spl_iterators.c — OuterIterator + Iterator.
            $this->ensureZendBuiltinInterfaces();
            $this->markInterfaceClass('OuterIterator');
            $this->setInterfaceExtends('OuterIterator', ['Iterator', 'Traversable']);
            $this->setClassInterfaces($displayName, [
                'OuterIterator',
                'Traversable',
                'Iterator',
            ]);
            $this->defineProperty($id, '__spl_ht', Variable::TYPE_HASHTABLE);
            // Parallel original leaf keys for iterator_to_array overwrite semantics (#27257).
            $this->defineProperty($id, \PHPCompiler\JIT\Builtin\RecursiveLeavesFlattenRuntime::PROP_KEYS, Variable::TYPE_HASHTABLE);
            $this->seedExternalClassConstants($id, [
                'LEAVES_ONLY' => 0,
                'SELF_FIRST' => 1,
                'CHILD_FIRST' => 2,
                'CATCH_GET_CHILD' => 16,
            ]);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach ([
                '__construct', 'rewind', 'valid', 'current', 'key', 'next',
                'getinneriterator', 'getdepth', 'setmaxdepth', 'getmaxdepth',
            ] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        if ('splmaxheap' === $lcname || 'splminheap' === $lcname || 'splheap' === $lcname) {
            // Thin AOT: `__spl_heap` packed storage + Iterator extract-on-next (#26784).
            // Zend subclass rematerializes Countable-first (#25822).
            $this->ensureTraversableBuiltinInterfaces();
            $this->setClassInterfaces($displayName, ['Countable', 'Iterator']);
            $this->defineProperty($id, \PHPCompiler\VM\SplHeapJitHelper::PROP_HEAP, Variable::TYPE_HASHTABLE);
            $this->defineProperty($id, \PHPCompiler\VM\SplHeapJitHelper::PROP_ITER_POS, Variable::TYPE_NATIVE_LONG);
            $this->defineProperty($id, \PHPCompiler\VM\SplHeapJitHelper::PROP_KIND, Variable::TYPE_NATIVE_LONG);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach ([
                '__construct', 'insert', 'extract', 'top', 'count', 'isempty',
                'rewind', 'valid', 'current', 'key', 'next',
            ] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        if ('splpriorityqueue' === $lcname) {
            // Thin AOT: parallel `__spl_data` / `__spl_prio` + Iterator extract-on-next (#27277, #28708).
            $this->ensureTraversableBuiltinInterfaces();
            $this->setClassInterfaces($displayName, ['Countable', 'Iterator']);
            $this->defineProperty($id, \PHPCompiler\VM\SplPriorityQueueJitHelper::PROP_DATA, Variable::TYPE_HASHTABLE);
            $this->defineProperty($id, \PHPCompiler\VM\SplPriorityQueueJitHelper::PROP_PRIO, Variable::TYPE_HASHTABLE);
            $this->defineProperty($id, \PHPCompiler\VM\SplPriorityQueueJitHelper::PROP_FLAGS, Variable::TYPE_NATIVE_LONG);
            $this->defineProperty($id, \PHPCompiler\VM\SplPriorityQueueJitHelper::PROP_ITER_POS, Variable::TYPE_NATIVE_LONG);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach ([
                '__construct', 'insert', 'extract', 'top', 'count', 'isempty',
                'setextractflags', 'getextractflags',
                'rewind', 'valid', 'current', 'key', 'next',
            ] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        if (
            'spldoublylinkedlist' === $lcname
            || 'splqueue' === $lcname
            || 'splstack' === $lcname
        ) {
            // Thin AOT: `__spl_ht` packed deque + FIFO/LIFO foreach (#26790, #27311, #28705).
            // SplQueue/DDL: forward nextFree walk; SplStack: descending (#28705).
            // Zend rematerializes Serializable-first subclass interfaces (#25797).
            $this->ensureTraversableBuiltinInterfaces();
            $this->setClassInterfaces($displayName, [
                'Serializable',
                'ArrayAccess',
                'Countable',
                'Traversable',
                'Iterator',
            ]);
            $this->defineProperty($id, \PHPCompiler\VM\SplDllistJitHelper::PROP_HT, Variable::TYPE_HASHTABLE);
            // Iterator mode for setIteratorMode/getIteratorMode / serialize bag (#33987).
            $this->defineProperty($id, \PHPCompiler\VM\SplDllistJitHelper::PROP_FLAGS, Variable::TYPE_NATIVE_LONG);
            // Iterator cursor for rewind/valid/current/key/next (#34976).
            $this->defineProperty($id, \PHPCompiler\VM\SplDllistJitHelper::PROP_ITER_POS, Variable::TYPE_NATIVE_LONG);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            $methods = [
                '__construct', 'push', 'pop', 'shift', 'unshift',
                'top', 'bottom', 'count', 'isempty',
                'offsetget', 'offsetexists', 'offsetset', 'offsetunset',
                'setiteratormode', 'getiteratormode',
                'rewind', 'valid', 'current', 'key', 'next',
            ];
            if ('splqueue' === $lcname) {
                $methods = array_merge($methods, ['enqueue', 'dequeue']);
            }
            foreach ($methods as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
            if ('spldoublylinkedlist' !== $lcname) {
                $this->setClassParentName($displayName, 'SplDoublyLinkedList');
            }
        }
        if ('splfixedarray' === $lcname) {
            // Thin AOT: `__spl_ht` packed storage + foreach via nextFreeElement (#26793, #28640).
            // php-src ext/spl/spl_fixedarray.stub.php — IteratorAggregate + ArrayAccess + Countable + JsonSerializable.
            $this->ensureZendBuiltinInterfaces();
            $this->markInterfaceClass('JsonSerializable');
            $this->setClassInterfaces($displayName, [
                'IteratorAggregate',
                'Traversable',
                'ArrayAccess',
                'Countable',
                'JsonSerializable',
            ]);
            $this->defineProperty($id, \PHPCompiler\VM\SplFixedArrayJitHelper::PROP_HT, Variable::TYPE_HASHTABLE);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            $pubStatic = $pub | \PHPCfg\Func::FLAG_STATIC;
            foreach ([
                '__construct', 'count', 'getsize', 'setsize', 'toarray', 'getiterator',
                'offsetget', 'offsetset', 'offsetexists', 'offsetunset', '__debuginfo',
                'jsonserialize',
            ] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
            $this->defineMethodVisibility($id, 'fromarray', $pubStatic);
        }
        if ('sensitiveparametervalue' === $lcname) {
            // Trace redaction marker — store wrapped arg for getValue() (#3351, #4621, #22487).
            // Private like Zend zend_exceptions.stub.php — json_encode must not leak (#23042).
            $this->defineProperty($id, 'value', Variable::TYPE_VALUE);
            $this->definePropertyVisibility($id, 'value', \PHPCfg\Func::FLAG_PRIVATE);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach (['__construct', 'getvalue', '__debuginfo'] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        // BcMath\Number props/methods: ext/bcmath/Module::jitInit seeder (#36204 / #24683).
        if ('weakreference' === $lcname) {
            $this->weakReferenceClassId = $id;
            // zend_weakrefs.c — clone_obj unset (#25962).
            $this->markDenyClone($id);
            $this->defineProperty($id, '__weak_target', Variable::TYPE_VALUE);
            $this->defineMethodVisibility(
                $id,
                'create',
                \PHPCfg\Func::FLAG_PUBLIC | \PHPCfg\Func::FLAG_STATIC
            );
        }
        if ('random\\randomizer' === $lcname) {
            $this->defineProperty($id, 'engine', Variable::TYPE_OBJECT);
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach ([
                '__construct', 'nextint', 'getint', 'getbytes', 'shufflearray', 'shufflebytes',
                'pickarraykeys', '__serialize', '__unserialize',
            ] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
            if (\PHPCompiler\CompilerVersion::supportsRandomIntervalBoundary()) {
                foreach (['nextfloat', 'getfloat', 'getbytesfromstring'] as $method) {
                    $this->defineMethodVisibility($id, $method, $pub);
                }
            }
        }
        if ('random\\engine\\mt19937' === $lcname) {
            $this->markHasConstructor($id);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach (['__construct', 'generate', '__serialize', '__unserialize', '__debuginfo'] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        if ('weakmap' === $lcname) {
            $this->weakMapClassId = $id;
            $this->defineProperty($id, '__weak_map', Variable::TYPE_HASHTABLE);
            // Zend/zend_weakrefs.c — ArrayAccess + Countable + IteratorAggregate (#22267).
            $this->setClassInterfaces($displayName, ['arrayaccess', 'countable', 'iteratoraggregate']);
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            foreach ([
                '__construct', 'offsetset', 'offsetget', 'offsetexists', 'offsetunset', 'count', 'getiterator',
            ] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
        }
        if ('streambucket' === $lcname) {
            // PHP 8.4+ final StreamBucket (user_filters.stub.php; #26923). ≤8.3 uses stdClass (#10325).
            if (\PHPCompiler\CompilerVersion::supportsStreamBucketClass()) {
                $this->classIdToName[$id] = 'StreamBucket';
                $this->defineProperty($id, 'bucket', Variable::TYPE_NATIVE_LONG);
                $this->defineProperty($id, 'data', Variable::TYPE_STRING);
                $this->defineProperty($id, 'datalen', Variable::TYPE_NATIVE_LONG);
                $this->defineProperty($id, 'dataLength', Variable::TYPE_NATIVE_LONG);
                $this->noDynamicPropertiesClassIds[$id] = true;
            }
        }
        if ('phpcompiler\\vm\\variable' === $lcname) {
            foreach ([
                'type_undefined' => \PHPCompiler\VM\Variable::TYPE_UNDEFINED,
                'type_null' => \PHPCompiler\VM\Variable::TYPE_NULL,
                'type_integer' => \PHPCompiler\VM\Variable::TYPE_INTEGER,
                'type_float' => \PHPCompiler\VM\Variable::TYPE_FLOAT,
                'type_boolean' => \PHPCompiler\VM\Variable::TYPE_BOOLEAN,
                'type_string' => \PHPCompiler\VM\Variable::TYPE_STRING,
                'type_array' => \PHPCompiler\VM\Variable::TYPE_ARRAY,
                'type_object' => \PHPCompiler\VM\Variable::TYPE_OBJECT,
                'type_indirect' => \PHPCompiler\VM\Variable::TYPE_INDIRECT,
                'type_string_offset' => \PHPCompiler\VM\Variable::TYPE_STRING_OFFSET,
            ] as $name => $value) {
                $this->classConstants[$id][$name] = [
                    'type' => Variable::TYPE_NATIVE_LONG,
                    'value' => $value,
                ];
            }
        }
        if ('phpparser\\parserfactory' === $lcname || 'parserfactory' === $lcname) {
            foreach ([
                'prefer_php7' => \PhpParser\ParserFactory::PREFER_PHP7,
                'prefer_php5' => \PhpParser\ParserFactory::PREFER_PHP5,
                'only_php7' => \PhpParser\ParserFactory::ONLY_PHP7,
                'only_php5' => \PhpParser\ParserFactory::ONLY_PHP5,
            ] as $name => $value) {
                $this->classConstants[$id][$name] = [
                    'type' => Variable::TYPE_NATIVE_LONG,
                    'value' => $value,
                ];
            }
        }
        if ('phpcompiler\\jit\\builtin' === $lcname || 'builtin' === $lcname) {
            foreach ([
                'load_type_export' => \PHPCompiler\JIT\Builtin::LOAD_TYPE_EXPORT,
                'load_type_import' => \PHPCompiler\JIT\Builtin::LOAD_TYPE_IMPORT,
                'load_type_embed' => \PHPCompiler\JIT\Builtin::LOAD_TYPE_EMBED,
                'load_type_standalone' => \PHPCompiler\JIT\Builtin::LOAD_TYPE_STANDALONE,
            ] as $name => $value) {
                $this->classConstants[$id][$name] = [
                    'type' => Variable::TYPE_NATIVE_LONG,
                    'value' => $value,
                ];
            }
        }
        if ('phpcfg\\script' === $lcname) {
            $this->defineProperty($id, 'main', Variable::TYPE_OBJECT);
        }
        // M5 C-floor wires these onto Parser for FORCE_PARSER NestedJIT (#27426).
        // Do not allocate PhpParser\Parser\Php7 here — that class lookup SEGVd argv rebuild
        // at c:main_before_php; use a lightweight peer (see RuntimeInitParsePipeline).
        if ('phpcfg\\parser' === $lcname) {
            foreach (['astParser', 'astTraverser', 'magicStringResolver'] as $prop) {
                $this->defineProperty($id, $prop, Variable::TYPE_OBJECT);
            }
        }
        // M5ParserAstPeer method slots for NestedJIT under FORCE_PARSER (#27426).
        // Include private helpers — parse() calls them; NestedJIT surface-only soft-failed.
        if ('phpcompiler\\jit\\m5parserastpeer' === $lcname || 'm5parserastpeer' === $lcname) {
            $pub = \PHPCfg\Func::FLAG_PUBLIC;
            $priv = \PHPCfg\Func::FLAG_PRIVATE;
            foreach (['parse', 'traverse', 'addvisitor', 'begincompilationunit'] as $method) {
                $this->defineMethodVisibility($id, $method, $pub);
            }
            foreach ([
                'stripleadingpreamble',
                'tryechostringast',
                'tryechointast',
                'tryassignplusechoast',
                'scanident',
                'scanunsignedint',
                'skipws',
            ] as $method) {
                $this->defineMethodVisibility($id, $method, $priv | \PHPCfg\Func::FLAG_STATIC);
            }
        }
        if ('phpcfg\\func' === $lcname) {
            $this->defineProperty($id, 'cfg', Variable::TYPE_OBJECT);
            foreach ([
                'flag_public' => \PHPCfg\Func::FLAG_PUBLIC,
                'flag_protected' => \PHPCfg\Func::FLAG_PROTECTED,
                'flag_private' => \PHPCfg\Func::FLAG_PRIVATE,
                'flag_static' => \PHPCfg\Func::FLAG_STATIC,
                'flag_abstract' => \PHPCfg\Func::FLAG_ABSTRACT,
                'flag_final' => \PHPCfg\Func::FLAG_FINAL,
                'flag_returns_ref' => \PHPCfg\Func::FLAG_RETURNS_REF,
                'flag_closure' => \PHPCfg\Func::FLAG_CLOSURE,
            ] as $name => $value) {
                $this->classConstants[$id][$name] = [
                    'type' => Variable::TYPE_NATIVE_LONG,
                    'value' => $value,
                ];
            }
        }
        if ('phpcompiler\\jit\\variable' === $lcname || 'variable' === $lcname) {
            foreach ([
                'type_null' => \PHPCompiler\JIT\Variable::TYPE_NULL,
                'type_native_long' => \PHPCompiler\JIT\Variable::TYPE_NATIVE_LONG,
                'type_native_bool' => \PHPCompiler\JIT\Variable::TYPE_NATIVE_BOOL,
                'type_native_double' => \PHPCompiler\JIT\Variable::TYPE_NATIVE_DOUBLE,
                'type_string' => \PHPCompiler\JIT\Variable::TYPE_STRING,
                'type_object' => \PHPCompiler\JIT\Variable::TYPE_OBJECT,
                'type_value' => \PHPCompiler\JIT\Variable::TYPE_VALUE,
                'type_hashtable' => \PHPCompiler\JIT\Variable::TYPE_HASHTABLE,
                'is_native_array' => \PHPCompiler\JIT\Variable::IS_NATIVE_ARRAY,
                'is_refcounted' => \PHPCompiler\JIT\Variable::IS_REFCOUNTED,
                'kind_variable' => \PHPCompiler\JIT\Variable::KIND_VARIABLE,
                'kind_value' => \PHPCompiler\JIT\Variable::KIND_VALUE,
            ] as $name => $value) {
                $this->classConstants[$id][$name] = [
                    'type' => Variable::TYPE_NATIVE_LONG,
                    'value' => $value,
                ];
            }
        }
        if ('phptypes\\type' === $lcname || 'type' === $lcname) {
            foreach (['type_null'=>\PHPTypes\Type::TYPE_NULL,'type_boolean'=>\PHPTypes\Type::TYPE_BOOLEAN,'type_long'=>\PHPTypes\Type::TYPE_LONG,'type_double'=>\PHPTypes\Type::TYPE_DOUBLE,'type_string'=>\PHPTypes\Type::TYPE_STRING,'type_object'=>\PHPTypes\Type::TYPE_OBJECT,'type_array'=>\PHPTypes\Type::TYPE_ARRAY,'type_callable'=>\PHPTypes\Type::TYPE_CALLABLE,'type_union'=>\PHPTypes\Type::TYPE_UNION,'type_intersection'=>\PHPTypes\Type::TYPE_INTERSECTION] as $name=>$value) {
                $this->classConstants[$id][$name] = ['type'=>Variable::TYPE_NATIVE_LONG,'value'=>$value];
            }
        }
        if ('phpcompiler\\runtime' === $lcname || 'runtime' === $lcname) {
            foreach ([
                'mode_normal' => \PHPCompiler\Runtime::MODE_NORMAL,
                'mode_aot' => \PHPCompiler\Runtime::MODE_AOT,
            ] as $name => $value) {
                $this->classConstants[$id][$name] = [
                    'type' => Variable::TYPE_NATIVE_LONG,
                    'value' => $value,
                ];
            }
        }
        if ('connectionstatus' === $lcname) {
            $this->enums[$lcname] = true;
            $this->setClassNoDynamicProperties($id, true);
            $this->setClassAllowsDynamicProperties($id, false);
            $this->setEnumBackedType($id, 'int');
            foreach ([
                'Normal' => \PHPCompiler\ext\standard\VmConnection::NORMAL,
                'Aborted' => \PHPCompiler\ext\standard\VmConnection::ABORTED,
                'Timeout' => \PHPCompiler\ext\standard\VmConnection::TIMEOUT,
            ] as $caseName => $value) {
                $backing = new VMVariable();
                $backing->int($value);
                $this->defineEnumCaseConst($id, $caseName, $backing);
            }
        }
        if ('roundingmode' === $lcname) {
            // php-src basic_functions.stub.php — unit enum (not int-backed) (#28535).
            $this->enums[$lcname] = true;
            $this->setClassNoDynamicProperties($id, true);
            $this->setClassAllowsDynamicProperties($id, false);
            foreach ([
                'HalfAwayFromZero',
                'HalfTowardsZero',
                'HalfEven',
                'HalfOdd',
                'TowardsZero',
                'AwayFromZero',
                'NegativeInfinity',
                'PositiveInfinity',
            ] as $caseName) {
                $backing = new VMVariable();
                $backing->null();
                $this->defineEnumCaseConst($id, $caseName, $backing);
            }
        }
        if ('arraypadtype' === $lcname && \PHPCompiler\CompilerVersion::supportsArrayPadTypeEnum()) {
            $this->enums[$lcname] = true;
            $this->setClassNoDynamicProperties($id, true);
            $this->setClassAllowsDynamicProperties($id, false);
            foreach (['Positive', 'Negative'] as $caseName) {
                $backing = new VMVariable();
                $backing->null();
                $this->defineEnumCaseConst($id, $caseName, $backing);
            }
        }
        if ('parseurl' === $lcname) {
            $this->enums[$lcname] = true;
            $this->setClassNoDynamicProperties($id, true);
            $this->setClassAllowsDynamicProperties($id, false);
            $this->setEnumBackedType($id, 'int');
            foreach ([
                'Scheme' => \PHPCompiler\ext\standard\VmParseUrl::PHP_URL_SCHEME,
                'Host' => \PHPCompiler\ext\standard\VmParseUrl::PHP_URL_HOST,
                'Port' => \PHPCompiler\ext\standard\VmParseUrl::PHP_URL_PORT,
                'User' => \PHPCompiler\ext\standard\VmParseUrl::PHP_URL_USER,
                'Pass' => \PHPCompiler\ext\standard\VmParseUrl::PHP_URL_PASS,
                'Path' => \PHPCompiler\ext\standard\VmParseUrl::PHP_URL_PATH,
                'Query' => \PHPCompiler\ext\standard\VmParseUrl::PHP_URL_QUERY,
                'Fragment' => \PHPCompiler\ext\standard\VmParseUrl::PHP_URL_FRAGMENT,
            ] as $caseName => $value) {
                $backing = new VMVariable();
                $backing->int($value);
                $this->defineEnumCaseConst($id, $caseName, $backing);
            }
        }
    }
}
