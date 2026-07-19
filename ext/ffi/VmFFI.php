<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ffi;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ExceptionSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmHttpBuildQuery;
use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmString;

/**
 * FFI VM class — host {@see \FFI} delegation (php-src ext/ffi/ffi.c; #4420).
 *
 * v1 surface: {@see FFI::cdef} + {@see __call} for declared C symbols (e.g. puts).
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

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && isset($ctx->classes[self::CLASS_LC]->methods['cdef'])) {
            return;
        }

        self::registerExceptions($ctx);

        $entry = $ctx->classes[self::CLASS_LC] ?? new ClassEntry('FFI');
        $entry->isInternal = true;

        $pub = CfgFunc::FLAG_PUBLIC;
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;

        $entry->methods['cdef'] = new FfiCdef();
        $entry->methodVisibility['cdef'] = $pubStatic;
        $entry->methodNames['cdef'] = 'cdef';

        $entry->methods['__call'] = new FfiCall();
        $entry->methodVisibility['__call'] = $pub;
        $entry->methodNames['__call'] = '__call';

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
        if (!isset($ctx->classes[self::CLASS_CDATA_LC])) {
            $cdata = new ClassEntry('FFI\\CData');
            $cdata->isInternal = true;
            $ctx->classes[self::CLASS_CDATA_LC] = $cdata;
        }
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

    public static function host(ObjectEntry $object): \FFI
    {
        if (!isset(self::$store[$object->id])) {
            throw new \Error('FFI object has no backing host handle');
        }

        return self::$store[$object->id];
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
     * @param list<mixed> $args host PHP scalars
     */
    public static function invoke(ObjectEntry $receiver, string $name, array $args): Variable
    {
        $host = self::host($receiver);
        try {
            $result = $host->$name(...$args);
        } catch (\FFI\Exception $e) {
            throw $e;
        }

        return self::importResult($result);
    }

    private static function importResult(mixed $result): Variable
    {
        if ($result instanceof \FFI\CData) {
            // v1: CData return not materialized as VM FFI\CData — scalar path only (#4420).
            throw new \Error('FFI\\CData return values are not supported in this compiler build');
        }
        if (\is_object($result) && $result instanceof \FFI) {
            throw new \Error('Nested FFI returns are not supported in this compiler build');
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
            $out[] = VmHttpBuildQuery::export($value, $frame);
        }

        return $out;
    }
}

/** Shared wiring for ext/ffi class methods (#4420). */
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
        $result = VmFFI::cdef($frame->vmContext, $code, $lib);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
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
        $result = VmFFI::invoke($receiver, $name, $args);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }
}
