<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ffi;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ExceptionSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmHttpBuildQuery;
use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmString;

/**
 * FFI VM class — host {@see \FFI} delegation (php-src ext/ffi/ffi.c; #4420, #22369).
 *
 * Surface: {@see FFI::cdef} + {@see __call} + {@see FFI::new}/{@see cast}/{@see typeof}/
 * {@see sizeof}/{@see addr}/{@see isNull}/{@see free} + {@see memcpy}/{@see memcmp}/{@see memset}/
 * {@see string}/{@see alignof}/{@see type} (#22369, #22760). CData property access via __get/__set.
 * JIT/AOT: VM-only (VmClassMethod::call throws).
 */
final class VmFFI
{
    public const CLASS_LC = 'ffi';
    public const CLASS_EXCEPTION_LC = 'ffi\\exception';
    public const CLASS_PARSER_EXCEPTION_LC = 'ffi\\parserexception';
    public const CLASS_CDATA_LC = 'ffi\\cdata';
    public const CLASS_CTYPE_LC = 'ffi\\ctype';

    /** @var array<int, \FFI> */
    private static array $store = [];

    /** @var array<int, \FFI\CData> */
    private static array $cdataStore = [];

    /** @var array<int, \FFI\CType> */
    private static array $ctypeStore = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && isset($ctx->classes[self::CLASS_LC]->methods['memcpy'])) {
            // Refresh CData dim handlers when only FFI statics were registered earlier (#22761).
            self::registerCDataClass($ctx);

            return;
        }

        self::registerExceptions($ctx);
        self::registerCDataClass($ctx);
        self::registerCTypeClass($ctx);

        $entry = $ctx->classes[self::CLASS_LC] ?? new ClassEntry('FFI');
        $entry->isInternal = true;

        $pub = CfgFunc::FLAG_PUBLIC;
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;

        $methods = [
            'cdef' => [new FfiCdef(), 'cdef', $pubStatic],
            '__call' => [new FfiCall(), '__call', $pub],
            'new' => [new FfiNew(), 'new', $pubStatic],
            'cast' => [new FfiCast(), 'cast', $pubStatic],
            'typeof' => [new FfiTypeof(), 'typeof', $pubStatic],
            'sizeof' => [new FfiSizeof(), 'sizeof', $pubStatic],
            'addr' => [new FfiAddr(), 'addr', $pubStatic],
            'isnull' => [new FfiIsNull(), 'isNull', $pubStatic],
            'free' => [new FfiFree(), 'free', $pubStatic],
            'memcpy' => [new FfiMemcpy(), 'memcpy', $pubStatic],
            'memcmp' => [new FfiMemcmp(), 'memcmp', $pubStatic],
            'memset' => [new FfiMemset(), 'memset', $pubStatic],
            'string' => [new FfiString(), 'string', $pubStatic],
            'alignof' => [new FfiAlignof(), 'alignof', $pubStatic],
            'type' => [new FfiType(), 'type', $pubStatic],
        ];
        foreach ($methods as $lc => [$handler, $name, $vis]) {
            $entry->methods[$lc] = $handler;
            $entry->methodVisibility[$lc] = $vis;
            $entry->methodNames[$lc] = $name;
        }

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function registerExceptions(Context $ctx): void
    {
        if (!isset($ctx->classes[self::CLASS_EXCEPTION_LC])) {
            $ex = self::newErrorFamilyEntry($ctx, 'FFI\\Exception', 'error');
            $ctx->classes[self::CLASS_EXCEPTION_LC] = $ex;
        }
        if (!isset($ctx->classes[self::CLASS_PARSER_EXCEPTION_LC])) {
            $parser = self::newErrorFamilyEntry($ctx, 'FFI\\ParserException', self::CLASS_EXCEPTION_LC);
            $ctx->classes[self::CLASS_PARSER_EXCEPTION_LC] = $parser;
        }
    }

    private static function registerCDataClass(Context $ctx): void
    {
        $cdata = $ctx->classes[self::CLASS_CDATA_LC] ?? new ClassEntry('FFI\\CData');
        $cdata->isInternal = true;
        // Dim handlers via ArrayAccess methods (php-src uses custom object handlers, not
        // ArrayAccess; VM requires the interface for $cdata[$i] — #22761).
        if (isset($ctx->classes['arrayaccess'])
            && !\in_array('arrayaccess', $cdata->interfaces, true)
        ) {
            $cdata->interfaces[] = 'arrayaccess';
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $cdata->methods['__get'] = new FfiCDataGet();
        $cdata->methodVisibility['__get'] = $pub;
        $cdata->methodNames['__get'] = '__get';
        $cdata->methods['__set'] = new FfiCDataSet();
        $cdata->methodVisibility['__set'] = $pub;
        $cdata->methodNames['__set'] = '__set';
        $cdata->methods['offsetget'] = new FfiCDataOffsetGet();
        $cdata->methodVisibility['offsetget'] = $pub;
        $cdata->methodNames['offsetget'] = 'offsetGet';
        $cdata->methods['offsetset'] = new FfiCDataOffsetSet();
        $cdata->methodVisibility['offsetset'] = $pub;
        $cdata->methodNames['offsetset'] = 'offsetSet';
        $cdata->methods['offsetexists'] = new FfiCDataOffsetExists();
        $cdata->methodVisibility['offsetexists'] = $pub;
        $cdata->methodNames['offsetexists'] = 'offsetExists';
        $cdata->methods['offsetunset'] = new FfiCDataOffsetUnset();
        $cdata->methodVisibility['offsetunset'] = $pub;
        $cdata->methodNames['offsetunset'] = 'offsetUnset';
        $ctx->classes[self::CLASS_CDATA_LC] = $cdata;
    }

    private static function registerCTypeClass(Context $ctx): void
    {
        if (!isset($ctx->classes[self::CLASS_CTYPE_LC])) {
            $ctype = new ClassEntry('FFI\\CType');
            $ctype->isInternal = true;
            $ctx->classes[self::CLASS_CTYPE_LC] = $ctype;
        }
    }

    /**
     * Register an Error-family throwable with zend_exceptions.stub.php slots
     * (same shape as {@see \PHPCompiler\VM\BuiltinClasses} Error descendants).
     */
    private static function newErrorFamilyEntry(Context $ctx, string $name, string $parentLc): ClassEntry
    {
        $entry = new ClassEntry($name);
        $entry->isInternal = true;
        if (isset($ctx->classes[$parentLc])) {
            $entry->parentLc = $parentLc;
        } elseif (isset($ctx->classes['error'])) {
            $entry->parentLc = 'error';
        }

        $strProto = new Variable(Variable::TYPE_STRING);
        $intProto = new Variable(Variable::TYPE_INTEGER);
        $nullProto = new Variable(Variable::TYPE_NULL);
        $arrayProto = new Variable(Variable::TYPE_ARRAY);
        $emptyTrace = new Variable();
        $emptyTrace->newArray();
        $prot = CfgFunc::FLAG_PROTECTED;
        $priv = CfgFunc::FLAG_PRIVATE;
        $errorLc = 'error';

        $entry->properties[] = new ClassProperty(ExceptionSupport::PROP_MESSAGE, null, $strProto, false, $prot);
        $entry->properties[] = new ClassProperty(ExceptionSupport::PROP_CODE, null, $intProto, false, $prot);
        $entry->properties[] = new ClassProperty(ExceptionSupport::PROP_FILE, null, $strProto, false, $prot);
        $entry->properties[] = new ClassProperty(ExceptionSupport::PROP_LINE, null, $intProto, false, $prot);
        $entry->properties[] = new ClassProperty(
            ExceptionSupport::PROP_PREVIOUS,
            null,
            $nullProto,
            false,
            $priv,
            $errorLc
        );
        $entry->properties[] = new ClassProperty(
            ExceptionSupport::PROP_TRACE,
            $emptyTrace,
            $arrayProto,
            false,
            $priv,
            $errorLc
        );

        return $entry;
    }

    public static function wrapHost(Context $ctx, \FFI $host): Variable
    {
        if (!isset($ctx->classes[self::CLASS_LC])) {
            throw new \LogicException('FFI builtin class is not registered');
        }
        $obj = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $obj->constructed = true;
        self::$store[$obj->id] = $host;
        $var = new Variable();
        $var->object($obj);

        return $var;
    }

    public static function wrapCData(Context $ctx, \FFI\CData $host): Variable
    {
        if (!isset($ctx->classes[self::CLASS_CDATA_LC])) {
            throw new \LogicException('FFI\\CData builtin class is not registered');
        }
        $obj = new ObjectEntry($ctx->classes[self::CLASS_CDATA_LC]);
        $obj->constructed = true;
        self::$cdataStore[$obj->id] = $host;
        $var = new Variable();
        $var->object($obj);

        return $var;
    }

    public static function wrapCType(Context $ctx, \FFI\CType $host): Variable
    {
        if (!isset($ctx->classes[self::CLASS_CTYPE_LC])) {
            throw new \LogicException('FFI\\CType builtin class is not registered');
        }
        $obj = new ObjectEntry($ctx->classes[self::CLASS_CTYPE_LC]);
        $obj->constructed = true;
        self::$ctypeStore[$obj->id] = $host;
        $var = new Variable();
        $var->object($obj);

        return $var;
    }

    public static function host(ObjectEntry $object): \FFI
    {
        if (!isset(self::$store[$object->id])) {
            throw new \Error('FFI object has no backing host handle');
        }

        return self::$store[$object->id];
    }

    public static function hostCData(ObjectEntry $object): \FFI\CData
    {
        if (!isset(self::$cdataStore[$object->id])) {
            throw new \Error('FFI\\CData object has no backing host handle');
        }

        return self::$cdataStore[$object->id];
    }

    public static function hostCType(ObjectEntry $object): \FFI\CType
    {
        if (!isset(self::$ctypeStore[$object->id])) {
            throw new \Error('FFI\\CType object has no backing host handle');
        }

        return self::$ctypeStore[$object->id];
    }

    public static function isCDataObject(ObjectEntry $object): bool
    {
        return isset(self::$cdataStore[$object->id]);
    }

    public static function isCTypeObject(ObjectEntry $object): bool
    {
        return isset(self::$ctypeStore[$object->id]);
    }

    public static function cdef(Context $ctx, string $code, ?string $lib): Variable
    {
        if (!FfiExtensionPolicy::hostFfiAvailable()) {
            throw new \Error('FFI extension is not available in this build');
        }
        try {
            $host = null !== $lib && '' !== $lib
                ? \FFI::cdef($code, $lib)
                : \FFI::cdef($code);
        } catch (\FFI\ParserException $e) {
            throw $e;
        } catch (\FFI\Exception $e) {
            throw $e;
        }

        return self::wrapHost($ctx, $host);
    }

    /**
     * @param list<mixed> $args host PHP scalars / CData
     */
    public static function invoke(Context $ctx, ObjectEntry $receiver, string $name, array $args): Variable
    {
        $host = self::host($receiver);
        try {
            $result = $host->$name(...$args);
        } catch (\FFI\Exception $e) {
            throw $e;
        }

        return self::importResult($ctx, $result);
    }

    public static function importResult(?Context $ctx, mixed $result): Variable
    {
        if ($result instanceof \FFI\CData) {
            if (null === $ctx) {
                throw new \Error('FFI\\CData return values require a VM context');
            }

            return self::wrapCData($ctx, $result);
        }
        if ($result instanceof \FFI\CType) {
            if (null === $ctx) {
                throw new \Error('FFI\\CType return values require a VM context');
            }

            return self::wrapCType($ctx, $result);
        }
        if (\is_object($result) && $result instanceof \FFI) {
            if (null === $ctx) {
                throw new \Error('Nested FFI returns require a VM context');
            }

            return self::wrapHost($ctx, $result);
        }

        return VmJson::import($result);
    }

    /**
     * @return list<mixed>
     */
    public static function exportArgs(Variable $argsVar, Frame $frame): array
    {
        $argsVar = $argsVar->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $argsVar->type) {
            return [];
        }
        $out = [];
        foreach ($argsVar->toArray()->iterateKeyed(true) as [$key, $value]) {
            $out[] = self::exportValue($value, $frame);
        }

        return $out;
    }

    public static function exportValue(Variable $value, ?Frame $frame = null): mixed
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_OBJECT === $value->type) {
            $obj = $value->toObject();
            if (self::isCDataObject($obj)) {
                return self::hostCData($obj);
            }
            if (self::isCTypeObject($obj)) {
                return self::hostCType($obj);
            }
            if (isset(self::$store[$obj->id])) {
                return self::host($obj);
            }
        }
        if (null === $frame) {
            return VmHttpBuildQuery::export($value, null);
        }

        return VmHttpBuildQuery::export($value, $frame);
    }

    /** @return string|\FFI\CType */
    public static function resolveTypeArg(Variable $var, string $label, int $index, string $paramName): string|\FFI\CType
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT === $var->type) {
            $obj = $var->toObject();
            if (self::isCTypeObject($obj)) {
                return self::hostCType($obj);
            }
        }
        if (Variable::TYPE_STRING === $var->type) {
            return $var->toString();
        }

        // Soft-coerce scalars that look like type strings (Zend accepts string|CType).
        try {
            return VmString::coerceStringBuiltinArg($var, $label, $index, $paramName);
        } catch (\TypeError $e) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type FFI\\CType|string, %s given',
                $label,
                $index + 1,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
    }

    public static function requireCDataArg(Variable $var, string $label, int $index, string $paramName): \FFI\CData
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type || !self::isCDataObject($var->toObject())) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type FFI\\CData, %s given',
                $label,
                $index + 1,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }

        return self::hostCData($var->toObject());
    }

    /** @return \FFI\CData|\FFI\CType */
    public static function requireCDataOrCTypeArg(
        Variable $var,
        string $label,
        int $index,
        string $paramName
    ): \FFI\CData|\FFI\CType {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT === $var->type) {
            $obj = $var->toObject();
            if (self::isCDataObject($obj)) {
                return self::hostCData($obj);
            }
            if (self::isCTypeObject($obj)) {
                return self::hostCType($obj);
            }
        }
        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type FFI\\CData|FFI\\CType, %s given',
            $label,
            $index + 1,
            $paramName,
            EnumCaseSupport::typeNameForVariable($var)
        ));
    }

    public static function coerceBoolArg(Variable $var, string $label, int $index, string $paramName): bool
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_BOOLEAN === $var->type) {
            return $var->toBool();
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            return 0 !== $var->toInt();
        }
        if (Variable::TYPE_NULL === $var->type) {
            return false;
        }
        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type bool, %s given',
            $label,
            $index + 1,
            $paramName,
            EnumCaseSupport::typeNameForVariable($var)
        ));
    }

    public static function coerceIntArg(Variable $var, string $label, int $index, string $paramName): int
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            return (int) $var->toFloat();
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            return $var->toBool() ? 1 : 0;
        }
        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type int, %s given',
            $label,
            $index + 1,
            $paramName,
            EnumCaseSupport::typeNameForVariable($var)
        ));
    }

    /**
     * Host value for memcpy/memcmp "from"/"ptr" args (CData|string|int…).
     */
    public static function exportMemcpySource(Variable $var, Frame $frame): mixed
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT === $var->type && self::isCDataObject($var->toObject())) {
            return self::hostCData($var->toObject());
        }
        if (Variable::TYPE_STRING === $var->type) {
            return $var->toString();
        }

        return self::exportValue($var, $frame);
    }
}

/** Shared wiring for ext/ffi class methods (#4420, #22369). */
abstract class FfiClassMethod extends VmClassMethod
{
    protected function receiver(Frame $frame, string $label): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($label.' called without $this');
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            throw new \TypeError(\sprintf('%s must be called on an object', $label));
        }

        return $var->toObject();
    }

    protected function returnImported(Frame $frame, Variable $result): void
    {
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }
}

/** FFI::cdef(string $code, ?string $lib = null): FFI */
final class FfiCdef extends FfiClassMethod
{
    public function __construct()
    {
        parent::__construct('cdef');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        // Static: calledArgs are user args only (no $this).
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'FFI::cdef() expects at least 1 argument and at most 2, '.$argc.' given'
            );
        }
        $code = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'FFI::cdef',
            0,
            'code'
        );
        $lib = null;
        if ($argc >= 2) {
            $libVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $libVar->type) {
                $lib = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[1],
                    'FFI::cdef',
                    1,
                    'lib'
                );
            }
        }
        $this->returnImported($frame, VmFFI::cdef($frame->vmContext, $code, $lib));
    }
}

/** FFI::__call — dynamic C symbol invoke (php-src zend_ffi_cdata_get_func_ptr). */
final class FfiCall extends FfiClassMethod
{
    public function __construct()
    {
        parent::__construct('__call');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(
                'FFI::__call() expects exactly 2 arguments, '.($argc - 1).' given'
            );
        }
        $receiver = $this->receiver($frame, 'FFI::__call()');
        $name = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'FFI::__call',
            0,
            'name'
        );
        $args = VmFFI::exportArgs($frame->calledArgs[2], $frame);
        $result = VmFFI::invoke($frame->vmContext, $receiver, $name, $args);
        $this->returnImported($frame, $result);
    }
}

/** FFI::new(FFI\CType|string $type, bool $owned = true, bool $persistent = false): ?FFI\CData */
final class FfiNew extends FfiClassMethod
{
    public function __construct()
    {
        parent::__construct('new');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(
                'FFI::new() expects at least 1 argument and at most 3, '.$argc.' given'
            );
        }
        if (!FfiExtensionPolicy::hostFfiAvailable()) {
            throw new \Error('FFI extension is not available in this build');
        }
        $type = VmFFI::resolveTypeArg($frame->calledArgs[0], 'FFI::new', 0, 'type');
        $owned = true;
        $persistent = false;
        if ($argc >= 2) {
            $owned = VmFFI::coerceBoolArg($frame->calledArgs[1], 'FFI::new', 1, 'owned');
        }
        if ($argc >= 3) {
            $persistent = VmFFI::coerceBoolArg($frame->calledArgs[2], 'FFI::new', 2, 'persistent');
        }
        try {
            $host = \FFI::new($type, $owned, $persistent);
        } catch (\FFI\Exception $e) {
            throw $e;
        }
        if (null === $host) {
            $null = new Variable();
            $null->null();
            $this->returnImported($frame, $null);

            return;
        }
        $this->returnImported($frame, VmFFI::wrapCData($frame->vmContext, $host));
    }
}

/** FFI::cast(FFI\CType|string $type, FFI\CData|int|float|bool|null $ptr): ?FFI\CData */
final class FfiCast extends FfiClassMethod
{
    public function __construct()
    {
        parent::__construct('cast');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(
                'FFI::cast() expects exactly 2 arguments, '.$argc.' given'
            );
        }
        if (!FfiExtensionPolicy::hostFfiAvailable()) {
            throw new \Error('FFI extension is not available in this build');
        }
        $type = VmFFI::resolveTypeArg($frame->calledArgs[0], 'FFI::cast', 0, 'type');
        $ptr = VmFFI::exportValue($frame->calledArgs[1], $frame);
        try {
            $host = \FFI::cast($type, $ptr);
        } catch (\FFI\Exception $e) {
            throw $e;
        } catch (\TypeError $e) {
            throw $e;
        }
        if (null === $host) {
            $null = new Variable();
            $null->null();
            $this->returnImported($frame, $null);

            return;
        }
        $this->returnImported($frame, VmFFI::wrapCData($frame->vmContext, $host));
    }
}

/** FFI::typeof(FFI\CData $ptr): FFI\CType */
final class FfiTypeof extends FfiClassMethod
{
    public function __construct()
    {
        parent::__construct('typeof');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'FFI::typeof() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (!FfiExtensionPolicy::hostFfiAvailable()) {
            throw new \Error('FFI extension is not available in this build');
        }
        $ptr = VmFFI::requireCDataArg($frame->calledArgs[0], 'FFI::typeof', 0, 'ptr');
        try {
            $host = \FFI::typeof($ptr);
        } catch (\FFI\Exception $e) {
            throw $e;
        }
        $this->returnImported($frame, VmFFI::wrapCType($frame->vmContext, $host));
    }
}

/** FFI::sizeof(FFI\CData|FFI\CType $ptr): int */
final class FfiSizeof extends FfiClassMethod
{
    public function __construct()
    {
        parent::__construct('sizeof');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'FFI::sizeof() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (!FfiExtensionPolicy::hostFfiAvailable()) {
            throw new \Error('FFI extension is not available in this build');
        }
        $ptr = VmFFI::requireCDataOrCTypeArg($frame->calledArgs[0], 'FFI::sizeof', 0, 'ptr');
        try {
            $size = \FFI::sizeof($ptr);
        } catch (\FFI\Exception $e) {
            throw $e;
        }
        $result = new Variable();
        $result->int($size);
        $this->returnImported($frame, $result);
    }
}

/** FFI::addr(FFI\CData $ptr): FFI\CData */
final class FfiAddr extends FfiClassMethod
{
    public function __construct()
    {
        parent::__construct('addr');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'FFI::addr() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (!FfiExtensionPolicy::hostFfiAvailable()) {
            throw new \Error('FFI extension is not available in this build');
        }
        $ptr = VmFFI::requireCDataArg($frame->calledArgs[0], 'FFI::addr', 0, 'ptr');
        try {
            $host = \FFI::addr($ptr);
        } catch (\FFI\Exception $e) {
            throw $e;
        }
        $this->returnImported($frame, VmFFI::wrapCData($frame->vmContext, $host));
    }
}

/** FFI::isNull(FFI\CData $ptr): bool */
final class FfiIsNull extends FfiClassMethod
{
    public function __construct()
    {
        parent::__construct('isNull');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'FFI::isNull() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (!FfiExtensionPolicy::hostFfiAvailable()) {
            throw new \Error('FFI extension is not available in this build');
        }
        $ptr = VmFFI::requireCDataArg($frame->calledArgs[0], 'FFI::isNull', 0, 'ptr');
        try {
            $isNull = \FFI::isNull($ptr);
        } catch (\FFI\Exception $e) {
            throw $e;
        }
        $result = new Variable();
        $result->bool($isNull);
        $this->returnImported($frame, $result);
    }
}

/** FFI::free(FFI\CData $ptr): void */
final class FfiFree extends FfiClassMethod
{
    public function __construct()
    {
        parent::__construct('free');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'FFI::free() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (!FfiExtensionPolicy::hostFfiAvailable()) {
            throw new \Error('FFI extension is not available in this build');
        }
        $ptr = VmFFI::requireCDataArg($frame->calledArgs[0], 'FFI::free', 0, 'ptr');
        try {
            \FFI::free($ptr);
        } catch (\FFI\Exception $e) {
            throw $e;
        }
    }
}

/** FFI::memcpy(FFI\CData $to, mixed $from, int $size): void (#22760) */
final class FfiMemcpy extends FfiClassMethod
{
    public function __construct()
    {
        parent::__construct('memcpy');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(
                'FFI::memcpy() expects exactly 3 arguments, '.$argc.' given'
            );
        }
        if (!FfiExtensionPolicy::hostFfiAvailable()) {
            throw new \Error('FFI extension is not available in this build');
        }
        $to = VmFFI::requireCDataArg($frame->calledArgs[0], 'FFI::memcpy', 0, 'to');
        $from = VmFFI::exportMemcpySource($frame->calledArgs[1], $frame);
        $size = VmFFI::coerceIntArg($frame->calledArgs[2], 'FFI::memcpy', 2, 'size');
        try {
            \FFI::memcpy($to, $from, $size);
        } catch (\FFI\Exception $e) {
            throw $e;
        } catch (\TypeError $e) {
            throw $e;
        }
    }
}

/** FFI::memcmp(mixed $ptr1, mixed $ptr2, int $size): int (#22760) */
final class FfiMemcmp extends FfiClassMethod
{
    public function __construct()
    {
        parent::__construct('memcmp');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(
                'FFI::memcmp() expects exactly 3 arguments, '.$argc.' given'
            );
        }
        if (!FfiExtensionPolicy::hostFfiAvailable()) {
            throw new \Error('FFI extension is not available in this build');
        }
        $ptr1 = VmFFI::exportMemcpySource($frame->calledArgs[0], $frame);
        $ptr2 = VmFFI::exportMemcpySource($frame->calledArgs[1], $frame);
        $size = VmFFI::coerceIntArg($frame->calledArgs[2], 'FFI::memcmp', 2, 'size');
        try {
            $cmp = \FFI::memcmp($ptr1, $ptr2, $size);
        } catch (\FFI\Exception $e) {
            throw $e;
        } catch (\TypeError $e) {
            throw $e;
        }
        $result = new Variable();
        $result->int($cmp);
        $this->returnImported($frame, $result);
    }
}

/** FFI::memset(FFI\CData $ptr, int $value, int $size): void (#22760) */
final class FfiMemset extends FfiClassMethod
{
    public function __construct()
    {
        parent::__construct('memset');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(
                'FFI::memset() expects exactly 3 arguments, '.$argc.' given'
            );
        }
        if (!FfiExtensionPolicy::hostFfiAvailable()) {
            throw new \Error('FFI extension is not available in this build');
        }
        $ptr = VmFFI::requireCDataArg($frame->calledArgs[0], 'FFI::memset', 0, 'ptr');
        $value = VmFFI::coerceIntArg($frame->calledArgs[1], 'FFI::memset', 1, 'value');
        $size = VmFFI::coerceIntArg($frame->calledArgs[2], 'FFI::memset', 2, 'size');
        try {
            \FFI::memset($ptr, $value, $size);
        } catch (\FFI\Exception $e) {
            throw $e;
        }
    }
}

/** FFI::string(FFI\CData $ptr, ?int $size = null): string (#22760) */
final class FfiString extends FfiClassMethod
{
    public function __construct()
    {
        parent::__construct('string');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'FFI::string() expects at least 1 argument and at most 2, '.$argc.' given'
            );
        }
        if (!FfiExtensionPolicy::hostFfiAvailable()) {
            throw new \Error('FFI extension is not available in this build');
        }
        $ptr = VmFFI::requireCDataArg($frame->calledArgs[0], 'FFI::string', 0, 'ptr');
        $size = null;
        if ($argc >= 2) {
            $sizeVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $sizeVar->type) {
                $size = VmFFI::coerceIntArg($frame->calledArgs[1], 'FFI::string', 1, 'size');
            }
        }
        try {
            $str = null === $size ? \FFI::string($ptr) : \FFI::string($ptr, $size);
        } catch (\FFI\Exception $e) {
            throw $e;
        }
        $result = new Variable();
        $result->string($str);
        $this->returnImported($frame, $result);
    }
}

/** FFI::alignof(FFI\CData|FFI\CType $ptr): int (#22760) */
final class FfiAlignof extends FfiClassMethod
{
    public function __construct()
    {
        parent::__construct('alignof');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'FFI::alignof() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (!FfiExtensionPolicy::hostFfiAvailable()) {
            throw new \Error('FFI extension is not available in this build');
        }
        $ptr = VmFFI::requireCDataOrCTypeArg($frame->calledArgs[0], 'FFI::alignof', 0, 'ptr');
        try {
            $align = \FFI::alignof($ptr);
        } catch (\FFI\Exception $e) {
            throw $e;
        }
        $result = new Variable();
        $result->int($align);
        $this->returnImported($frame, $result);
    }
}

/** FFI::type(string $type): ?FFI\CType (#22760) */
final class FfiType extends FfiClassMethod
{
    public function __construct()
    {
        parent::__construct('type');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'FFI::type() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (!FfiExtensionPolicy::hostFfiAvailable()) {
            throw new \Error('FFI extension is not available in this build');
        }
        $type = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'FFI::type',
            0,
            'type'
        );
        try {
            $host = \FFI::type($type);
        } catch (\FFI\ParserException $e) {
            throw $e;
        } catch (\FFI\Exception $e) {
            throw $e;
        }
        if (null === $host) {
            $null = new Variable();
            $null->null();
            $this->returnImported($frame, $null);

            return;
        }
        $this->returnImported($frame, VmFFI::wrapCType($frame->vmContext, $host));
    }
}

/** FFI\CData::__get — scalar/struct field read (php-src zend_ffi_cdata_read). */
final class FfiCDataGet extends FfiClassMethod
{
    public function __construct()
    {
        parent::__construct('__get');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                'FFI\\CData::__get() expects exactly 1 argument, '.($argc - 1).' given'
            );
        }
        $receiver = $this->receiver($frame, 'FFI\\CData::__get()');
        $name = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'FFI\\CData::__get',
            0,
            'name'
        );
        $host = VmFFI::hostCData($receiver);
        try {
            $result = $host->$name;
        } catch (\FFI\Exception $e) {
            throw $e;
        } catch (\Error $e) {
            throw $e;
        }
        $this->returnImported($frame, VmFFI::importResult($frame->vmContext, $result));
    }
}

/** FFI\CData::__set — scalar/struct field write (php-src zend_ffi_cdata_write). */
final class FfiCDataSet extends FfiClassMethod
{
    public function __construct()
    {
        parent::__construct('__set');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(
                'FFI\\CData::__set() expects exactly 2 arguments, '.($argc - 1).' given'
            );
        }
        $receiver = $this->receiver($frame, 'FFI\\CData::__set()');
        $name = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'FFI\\CData::__set',
            0,
            'name'
        );
        $value = VmFFI::exportValue($frame->calledArgs[2], $frame);
        $host = VmFFI::hostCData($receiver);
        try {
            $host->$name = $value;
        } catch (\FFI\Exception $e) {
            throw $e;
        } catch (\Error $e) {
            throw $e;
        } catch (\TypeError $e) {
            throw $e;
        }
    }
}

/** FFI\CData::offsetGet — C array dim read (php-src zend_ffi_cdata_read_dim; #22761). */
final class FfiCDataOffsetGet extends FfiClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetGet');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                'FFI\\CData::offsetGet() expects exactly 1 argument, '.($argc - 1).' given'
            );
        }
        $receiver = $this->receiver($frame, 'FFI\\CData::offsetGet()');
        $offset = VmFFI::coerceIntArg($frame->calledArgs[1], 'FFI\\CData::offsetGet', 0, 'offset');
        $host = VmFFI::hostCData($receiver);
        try {
            $result = $host[$offset];
        } catch (\FFI\Exception $e) {
            throw $e;
        } catch (\Error $e) {
            throw $e;
        }
        $this->returnImported($frame, VmFFI::importResult($frame->vmContext, $result));
    }
}

/** FFI\CData::offsetSet — C array dim write (php-src zend_ffi_cdata_write_dim; #22761). */
final class FfiCDataOffsetSet extends FfiClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetSet');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(
                'FFI\\CData::offsetSet() expects exactly 2 arguments, '.($argc - 1).' given'
            );
        }
        $receiver = $this->receiver($frame, 'FFI\\CData::offsetSet()');
        $offset = VmFFI::coerceIntArg($frame->calledArgs[1], 'FFI\\CData::offsetSet', 0, 'offset');
        $value = VmFFI::exportValue($frame->calledArgs[2], $frame);
        $host = VmFFI::hostCData($receiver);
        try {
            $host[$offset] = $value;
        } catch (\FFI\Exception $e) {
            throw $e;
        } catch (\Error $e) {
            throw $e;
        } catch (\TypeError $e) {
            throw $e;
        }
    }
}

/**
 * FFI\CData::offsetExists — Zend rejects isset($cdata[$i]) with "Cannot use object … as array"
 * (no ArrayAccess in php-src; custom handlers only cover read/write dim).
 */
final class FfiCDataOffsetExists extends FfiClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetExists');
    }

    public function execute(Frame $frame): void
    {
        throw new \Error('Cannot use object of type FFI\\CData as array');
    }
}

/** FFI\CData::offsetUnset — same Zend rejection as isset (#22761). */
final class FfiCDataOffsetUnset extends FfiClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetUnset');
    }

    public function execute(Frame $frame): void
    {
        throw new \Error('Cannot use object of type FFI\\CData as array');
    }
}
