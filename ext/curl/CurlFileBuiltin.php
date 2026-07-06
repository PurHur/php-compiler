<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func;

/**
 * CURLFile — on-disk multipart upload payload (php-src ext/curl/curl_file.c; #6790).
 */
final class CurlFileBuiltin
{
    public const CLASS_LC = 'curlfile';

    public static function register(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('CURLFile');
        $entry->isInternal = true;
        $strProto = new Variable();
        $strProto->type = Variable::TYPE_STRING;
        $pub = Func::FLAG_PUBLIC;
        foreach (['name', 'mime', 'postname'] as $prop) {
            $entry->properties[] = new ClassProperty($prop, null, $strProto, false, $pub, self::CLASS_LC);
        }
        $entry->constructor = new CurlFileConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach ([
            'getfilename' => CurlFileGetFilename::class,
            'getmimetype' => CurlFileGetMimeType::class,
            'getpostfilename' => CurlFileGetPostFilename::class,
            'setmimetype' => CurlFileSetMimeType::class,
            'setpostfilename' => CurlFileSetPostFilename::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['getfilename'] = 'getFilename';
        $entry->methodNames['getmimetype'] = 'getMimeType';
        $entry->methodNames['getpostfilename'] = 'getPostFilename';
        $entry->methodNames['setmimetype'] = 'setMimeType';
        $entry->methodNames['setpostfilename'] = 'setPostFilename';

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function receiver(Frame $frame, string $signature): ObjectEntry
    {
        if (!isset($frame->calledArgs[0])) {
            throw new \LogicException($signature.' requires object receiver');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \TypeError($signature.' must be called on CURLFile instance');
        }
        $object = $receiver->toObject();
        if (self::CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError($signature.' must be called on CURLFile instance');
        }

        return $object;
    }

    public static function create(
        Context $ctx,
        string $filename,
        ?string $mimeType = null,
        ?string $postedFilename = null
    ): Variable {
        self::register($ctx);
        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('CURLFile is not registered in this compiler build');
        }
        $object = new ObjectEntry($class);
        $object->constructed = true;
        self::initProperties($object, $filename, $mimeType, $postedFilename);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    public static function initProperties(
        ObjectEntry $object,
        string $filename,
        ?string $mimeType,
        ?string $postedFilename
    ): void {
        $object->getProperty('name')->string($filename);
        if (null !== $mimeType) {
            $object->getProperty('mime')->string($mimeType);
        } else {
            $object->getProperty('mime')->string('');
        }
        if (null !== $postedFilename) {
            $object->getProperty('postname')->string($postedFilename);
        } else {
            $object->getProperty('postname')->string('');
        }
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset(
            $entry->constructor,
            $entry->methods['getfilename'],
            $entry->methods['setmimetype']
        );
    }
}

final class CurlFileConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = CurlFileBuiltin::receiver($frame, 'CURLFile::__construct()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(\sprintf(
                'CURLFile::__construct() expects at least 1 argument, %d given',
                \count($frame->calledArgs) - 1
            ));
        }
        if (\count($frame->calledArgs) > 4) {
            throw new \ArgumentCountError(\sprintf(
                'CURLFile::__construct() expects at most 3 arguments, %d given',
                \count($frame->calledArgs) - 1
            ));
        }
        $filename = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'CURLFile::__construct', 0, 'filename');
        $mimeType = null;
        if (isset($frame->calledArgs[2]) && Variable::TYPE_NULL !== $frame->calledArgs[2]->resolveIndirect()->type) {
            $mimeType = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'CURLFile::__construct', 1, 'mime_type');
        }
        $postedFilename = null;
        if (isset($frame->calledArgs[3]) && Variable::TYPE_NULL !== $frame->calledArgs[3]->resolveIndirect()->type) {
            $postedFilename = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'CURLFile::__construct', 2, 'posted_filename');
        }
        CurlFileBuiltin::initProperties($object, $filename, $mimeType, $postedFilename);
    }
}

final class CurlFileGetFilename extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getFilename');
    }

    public function execute(Frame $frame): void
    {
        $object = CurlFileBuiltin::receiver($frame, 'CURLFile::getFilename()');
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string($object->getProperty('name')->toString())
        );
    }
}

final class CurlFileGetMimeType extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getMimeType');
    }

    public function execute(Frame $frame): void
    {
        $object = CurlFileBuiltin::receiver($frame, 'CURLFile::getMimeType()');
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string($object->getProperty('mime')->toString())
        );
    }
}

final class CurlFileGetPostFilename extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getPostFilename');
    }

    public function execute(Frame $frame): void
    {
        $object = CurlFileBuiltin::receiver($frame, 'CURLFile::getPostFilename()');
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string($object->getProperty('postname')->toString())
        );
    }
}

final class CurlFileSetMimeType extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setMimeType');
    }

    public function execute(Frame $frame): void
    {
        $object = CurlFileBuiltin::receiver($frame, 'CURLFile::setMimeType()');
        if (!isset($frame->calledArgs[1])) {
            throw new \ArgumentCountError('CURLFile::setMimeType() expects exactly 1 argument, 0 given');
        }
        $mime = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'CURLFile::setMimeType', 0, 'mime_type');
        $object->getProperty('mime')->string($mime);
    }
}

final class CurlFileSetPostFilename extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setPostFilename');
    }

    public function execute(Frame $frame): void
    {
        $object = CurlFileBuiltin::receiver($frame, 'CURLFile::setPostFilename()');
        if (!isset($frame->calledArgs[1])) {
            throw new \ArgumentCountError('CURLFile::setPostFilename() expects exactly 1 argument, 0 given');
        }
        $postname = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'CURLFile::setPostFilename', 0, 'posted_filename');
        $object->getProperty('postname')->string($postname);
    }
}
