<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func;

/**
 * CURLStringFile — in-memory multipart payload (php-src ext/curl/curl_file.stub.php; #6918, #16659).
 */
final class CurlStringFileBuiltin
{
    public const CLASS_LC = 'curlstringfile';

    public static function register(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('CURLStringFile');
        $entry->isInternal = true;
        $strProto = new Variable();
        $strProto->type = Variable::TYPE_STRING;
        $pub = Func::FLAG_PUBLIC;
        foreach (['data', 'postname', 'mime'] as $prop) {
            $entry->properties[] = new ClassProperty($prop, null, $strProto, false, $pub, self::CLASS_LC);
        }
        $entry->constructor = new CurlStringFileConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function receiver(Frame $frame, string $signature): ObjectEntry
    {
        if (!isset($frame->calledArgs[0])) {
            throw new \LogicException($signature.' requires object receiver');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \TypeError($signature.' must be called on CURLStringFile instance');
        }
        $object = $receiver->toObject();
        if (self::CLASS_LC !== strtolower($object->class->name)) {
            throw new \TypeError($signature.' must be called on CURLStringFile instance');
        }

        return $object;
    }
}

final class CurlStringFileConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = CurlStringFileBuiltin::receiver($frame, 'CURLStringFile::__construct()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(\sprintf(
                'CURLStringFile::__construct() expects at least 2 arguments, %d given',
                \count($frame->calledArgs) - 1
            ));
        }
        if (\count($frame->calledArgs) > 4) {
            throw new \ArgumentCountError(\sprintf(
                'CURLStringFile::__construct() expects at most 3 arguments, %d given',
                \count($frame->calledArgs) - 1
            ));
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'CURLStringFile::__construct', 0, 'data');
        $postname = VmString::coerceStringBuiltinArg($frame->calledArgs[2], 'CURLStringFile::__construct', 1, 'postname');
        $mime = 'application/octet-stream';
        if (isset($frame->calledArgs[3])) {
            $mime = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'CURLStringFile::__construct', 2, 'mime');
        }
        $object->getProperty('data')->string($data);
        $object->getProperty('postname')->string($postname);
        $object->getProperty('mime')->string($mime);
    }
}
