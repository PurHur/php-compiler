<?php

declare(strict_types=1);

namespace PHPCompiler\ext\phar;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\PharRunning;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\spl\FilesystemIteratorBuiltin;
use PHPCompiler\ext\spl\RecursiveDirectoryIteratorBuiltin;

/** Register ext/phar builtin classes (php-src ext/phar/phar.stub.php; #3436, #6490, #19871). */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        self::registerPhar($ctx);
        PharBuiltin::registerInstanceMethods($ctx);
        PharDataBuiltin::register($ctx);
        VmPharFileInfo::register($ctx);
    }

    public static function registerPhar(Context $ctx): void
    {
        if (isset($ctx->classes[VmPhar::CLASS_LC])
            && isset($ctx->classes[VmPhar::CLASS_LC]->methods['canwrite'])
            && isset($ctx->classes[VmPhar::CLASS_LC]->methods['addfromstring'])
            && isset($ctx->classes[VmPhar::CLASS_LC]->methods['loadphar'])
            && isset($ctx->classes[VmPhar::CLASS_LC]->methods['mapphar'])
            && isset($ctx->classes[VmPhar::CLASS_LC]->methods['webphar'])
            && isset($ctx->classes[VmPhar::CLASS_LC]->methods['mount'])
            && isset($ctx->classes[VmPhar::CLASS_LC]->methods['mungserver'])
            && isset($ctx->classes[VmPhar::CLASS_LC]->methods['getsupportedcompression'])
            && isset($ctx->classes[VmPhar::CLASS_LC]->methods['getsupportedsignatures'])) {
            return;
        }

        // php-src: Phar extends RecursiveDirectoryIterator (phar_object.c / phar.stub.php; #22293).
        FilesystemIteratorBuiltin::registerClass($ctx);

        $pubStatic = CfgFunc::FLAG_PUBLIC | CfgFunc::FLAG_STATIC;
        $entry = $ctx->classes[VmPhar::CLASS_LC] ?? new ClassEntry('Phar');
        $entry->isInternal = true;
        $entry->parentLc = RecursiveDirectoryIteratorBuiltin::CLASS_LC;

        $entry->methods['running'] = new PharRunning();
        $entry->methodVisibility['running'] = $pubStatic;
        $entry->methodNames['running'] = 'running';

        $entry->methods['canwrite'] = new PharCanWrite();
        $entry->methodVisibility['canwrite'] = $pubStatic;
        $entry->methodNames['canwrite'] = 'canWrite';

        $entry->methods['cancompress'] = new PharCanCompress();
        $entry->methodVisibility['cancompress'] = $pubStatic;
        $entry->methodNames['cancompress'] = 'canCompress';

        $entry->methods['getsupportedcompression'] = new PharGetSupportedCompression();
        $entry->methodVisibility['getsupportedcompression'] = $pubStatic;
        $entry->methodNames['getsupportedcompression'] = 'getSupportedCompression';

        $entry->methods['getsupportedsignatures'] = new PharGetSupportedSignatures();
        $entry->methodVisibility['getsupportedsignatures'] = $pubStatic;
        $entry->methodNames['getsupportedsignatures'] = 'getSupportedSignatures';

        $entry->methods['apiversion'] = new PharApiVersion();
        $entry->methodVisibility['apiversion'] = $pubStatic;
        $entry->methodNames['apiversion'] = 'apiVersion';

        $entry->methods['isvalidpharfilename'] = new PharIsValidPharFilename();
        $entry->methodVisibility['isvalidpharfilename'] = $pubStatic;
        $entry->methodNames['isvalidpharfilename'] = 'isValidPharFilename';

        $entry->methods['loadphar'] = new PharLoadPhar();
        $entry->methodVisibility['loadphar'] = $pubStatic;
        $entry->methodNames['loadphar'] = 'loadPhar';

        $entry->methods['unlinkarchive'] = new PharUnlinkArchive();
        $entry->methodVisibility['unlinkarchive'] = $pubStatic;
        $entry->methodNames['unlinkarchive'] = 'unlinkArchive';

        $entry->methods['mapphar'] = new PharMapPhar();
        $entry->methodVisibility['mapphar'] = $pubStatic;
        $entry->methodNames['mapphar'] = 'mapPhar';

        $entry->methods['interceptfilefuncs'] = new PharInterceptFileFuncs();
        $entry->methodVisibility['interceptfilefuncs'] = $pubStatic;
        $entry->methodNames['interceptfilefuncs'] = 'interceptFileFuncs';

        $entry->methods['mount'] = new PharMount();
        $entry->methodVisibility['mount'] = $pubStatic;
        $entry->methodNames['mount'] = 'mount';

        $entry->methods['mungserver'] = new PharMungServer();
        $entry->methodVisibility['mungserver'] = $pubStatic;
        $entry->methodNames['mungserver'] = 'mungServer';

        $entry->methods['webphar'] = new PharWebPhar();
        $entry->methodVisibility['webphar'] = $pubStatic;
        $entry->methodNames['webphar'] = 'webPhar';

        if (!isset($ctx->classes['pharexception'])) {
            $pharEx = new ClassEntry('PharException');
            if (isset($ctx->classes['exception'])) {
                $pharEx->parentLc = 'exception';
            }
            $ctx->classes['pharexception'] = $pharEx;
        }

        foreach ([
            'none' => VmPhar::COMPRESSED_NONE,
            'gz' => VmPhar::COMPRESSED_GZ,
            'bz2' => VmPhar::COMPRESSED_BZ2,
            'phar' => VmPhar::FORMAT_PHAR,
            'tar' => VmPhar::FORMAT_TAR,
            'zip' => VmPhar::FORMAT_ZIP,
            'md5' => VmPhar::SIG_MD5,
            'sha1' => VmPhar::SIG_SHA1,
            'sha256' => VmPhar::SIG_SHA256,
            'sha512' => VmPhar::SIG_SHA512,
            'openssl' => VmPhar::SIG_OPENSSL,
            'openssl_sha256' => VmPhar::SIG_OPENSSL_SHA256,
            'openssl_sha512' => VmPhar::SIG_OPENSSL_SHA512,
        ] as $lc => $value) {
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$lc] = $const;
            $entry->constNames[$lc] = strtoupper($lc);
        }

        $ctx->classes[VmPhar::CLASS_LC] = $entry;
    }
}

/** Phar::canWrite() — php-src zim_Phar_canWrite (#19871). */
final class PharCanWrite extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('canWrite');
    }

    public function execute(Frame $frame): void
    {
        BuiltinExecute::run($frame, static function (Frame $frame): void {
            $result = VmPhar::canWrite();
            BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
                $ret->bool($result);
            });
        });
    }
}

/** Phar::canCompress() — php-src zim_Phar_canCompress (#19871). */
final class PharCanCompress extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('canCompress');
    }

    public function execute(Frame $frame): void
    {
        BuiltinExecute::run($frame, static function (Frame $frame): void {
            $method = 0;
            if (\count($frame->calledArgs) >= 1) {
                $method = $frame->calledArgs[0]->resolveIndirect()->toInt();
            }
            $result = VmPhar::canCompress($method);
            BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
                $ret->bool($result);
            });
        });
    }
}

/** Phar::getSupportedCompression() — php-src zim_Phar_getSupportedCompression (#21650). */
final class PharGetSupportedCompression extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getSupportedCompression');
    }

    public function execute(Frame $frame): void
    {
        BuiltinExecute::run($frame, static function (Frame $frame): void {
            $list = VmPhar::getSupportedCompression();
            BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($list): void {
                $ret->array(VmPharArchive::mapToHashTable($list));
            });
        });
    }
}

/** Phar::getSupportedSignatures() — php-src zim_Phar_getSupportedSignatures (#21650). */
final class PharGetSupportedSignatures extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getSupportedSignatures');
    }

    public function execute(Frame $frame): void
    {
        BuiltinExecute::run($frame, static function (Frame $frame): void {
            $list = VmPhar::getSupportedSignatures();
            BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($list): void {
                $ret->array(VmPharArchive::mapToHashTable($list));
            });
        });
    }
}

/** Phar::apiVersion() — php-src zim_Phar_apiVersion (#19871). */
final class PharApiVersion extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('apiVersion');
    }

    public function execute(Frame $frame): void
    {
        BuiltinExecute::run($frame, static function (Frame $frame): void {
            BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
                $ret->string(VmPhar::API_VERSION);
            });
        });
    }
}

/** Phar::isValidPharFilename() — php-src zim_Phar_isValidPharFilename (#19871). */
final class PharIsValidPharFilename extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isValidPharFilename');
    }

    public function execute(Frame $frame): void
    {
        BuiltinExecute::run($frame, static function (Frame $frame): void {
            $argc = \count($frame->calledArgs);
            if ($argc < 1) {
                throw new \ArgumentCountError(
                    'Phar::isValidPharFilename() expects at least 1 argument, 0 given'
                );
            }
            $filename = $frame->calledArgs[0]->resolveIndirect()->toString();
            $executable = true;
            if ($argc >= 2) {
                $executable = $frame->calledArgs[1]->resolveIndirect()->toBool();
            }
            $result = VmPhar::isValidPharFilename($filename, $executable);
            BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
                $ret->bool($result);
            });
        });
    }
}

/** Phar::loadPhar() — php-src phar_load (#21232). */
final class PharLoadPhar extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('loadPhar');
    }

    public function execute(Frame $frame): void
    {
        BuiltinExecute::run($frame, static function (Frame $frame): void {
            $argc = \count($frame->calledArgs);
            if ($argc < 1) {
                throw new \ArgumentCountError('Phar::loadPhar() expects at least 1 argument, 0 given');
            }
            $filename = $frame->calledArgs[0]->resolveIndirect()->toString();
            $alias = '';
            if ($argc >= 2) {
                $alias = $frame->calledArgs[1]->resolveIndirect()->toString();
            }
            $result = VmPharArchive::loadPhar($filename, $alias);
            BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
                $ret->bool($result);
            });
        });
    }
}

/** Phar::unlinkArchive() — php-src phar_unlink_archive (#21232). */
final class PharUnlinkArchive extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('unlinkArchive');
    }

    public function execute(Frame $frame): void
    {
        BuiltinExecute::run($frame, static function (Frame $frame): void {
            $argc = \count($frame->calledArgs);
            if ($argc < 1) {
                throw new \ArgumentCountError('Phar::unlinkArchive() expects at least 1 argument, 0 given');
            }
            $archive = $frame->calledArgs[0]->resolveIndirect()->toString();
            $result = VmPharArchive::unlinkArchive($archive);
            BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
                $ret->bool($result);
            });
        });
    }
}

/** Phar::mapPhar() — php-src zim_Phar_mapPhar (#21338). */
final class PharMapPhar extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('mapPhar');
    }

    public function execute(Frame $frame): void
    {
        BuiltinExecute::run($frame, static function (Frame $frame): void {
            $argc = \count($frame->calledArgs);
            if ($argc > 2) {
                throw new \ArgumentCountError(
                    'Phar::mapPhar() expects at most 2 arguments, '.$argc.' given'
                );
            }
            $alias = null;
            if ($argc >= 1) {
                $arg0 = $frame->calledArgs[0]->resolveIndirect();
                if (!$arg0->isNull()) {
                    $alias = $arg0->toString();
                }
            }
            $dataoffset = 0;
            if ($argc >= 2) {
                $dataoffset = $frame->calledArgs[1]->resolveIndirect()->toInt();
            }
            $scriptPath = PharRunning::resolveScriptPath($frame);
            $result = VmPharStream::mapPhar($alias, $dataoffset, $scriptPath);
            BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
                $ret->bool($result);
            });
        });
    }
}

/** Phar::interceptFileFuncs() — php-src zim_Phar_interceptFileFuncs (#21338). */
final class PharInterceptFileFuncs extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('interceptFileFuncs');
    }

    public function execute(Frame $frame): void
    {
        BuiltinExecute::run($frame, static function (Frame $frame): void {
            $argc = \count($frame->calledArgs);
            if ($argc > 0) {
                throw new \ArgumentCountError(
                    'Phar::interceptFileFuncs() expects exactly 0 arguments, '.$argc.' given'
                );
            }
            VmPharStream::enableInterceptFileFuncs();
        });
    }
}

/** Phar::mount() — php-src zim_Phar_mount (#21327). */
final class PharMount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('mount');
    }

    public function execute(Frame $frame): void
    {
        BuiltinExecute::run($frame, static function (Frame $frame): void {
            $argc = \count($frame->calledArgs);
            if ($argc < 2) {
                throw new \ArgumentCountError(
                    'Phar::mount() expects exactly 2 arguments, '.$argc.' given'
                );
            }
            $internal = $frame->calledArgs[0]->resolveIndirect()->toString();
            $external = $frame->calledArgs[1]->resolveIndirect()->toString();
            $scriptPath = PharRunning::resolveScriptPath($frame);
            VmPharStream::mount($internal, $external, $scriptPath);
        });
    }
}

/** Phar::mungServer() — php-src zim_Phar_mungServer (#21327). */
final class PharMungServer extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('mungServer');
    }

    public function execute(Frame $frame): void
    {
        BuiltinExecute::run($frame, static function (Frame $frame): void {
            $argc = \count($frame->calledArgs);
            if ($argc < 1) {
                throw new \ArgumentCountError(
                    'Phar::mungServer() expects exactly 1 argument, 0 given'
                );
            }
            $arg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $arg->type) {
                throw new \TypeError('Phar::mungServer(): Argument #1 ($variables) must be of type array');
            }
            $names = [];
            foreach ($arg->toArray()->iterateKeyed(true) as [, $val]) {
                if (Variable::TYPE_STRING !== $val->type) {
                    throw new \PharException(
                        'Non-string value passed to Phar::mungServer(), expecting an array of any of these strings: PHP_SELF, REQUEST_URI, SCRIPT_FILENAME, SCRIPT_NAME'
                    );
                }
                $names[] = $val->toString();
            }
            VmPharStream::mungServer($names);
        });
    }
}

/** Phar::webPhar() — php-src zim_Phar_webPhar (#21327). */
final class PharWebPhar extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('webPhar');
    }

    public function execute(Frame $frame): void
    {
        BuiltinExecute::run($frame, static function (Frame $frame): void {
            $argc = \count($frame->calledArgs);
            if ($argc > 5) {
                throw new \ArgumentCountError(
                    'Phar::webPhar() expects at most 5 arguments, '.$argc.' given'
                );
            }
            $alias = null;
            if ($argc >= 1) {
                $a0 = $frame->calledArgs[0]->resolveIndirect();
                if (!$a0->isNull()) {
                    $alias = $a0->toString();
                }
            }
            $index = null;
            if ($argc >= 2) {
                $a1 = $frame->calledArgs[1]->resolveIndirect();
                if (!$a1->isNull()) {
                    $index = $a1->toString();
                }
            }
            $f404 = null;
            if ($argc >= 3) {
                $a2 = $frame->calledArgs[2]->resolveIndirect();
                if (!$a2->isNull()) {
                    $f404 = $a2->toString();
                }
            }
            $mimeTypes = [];
            if ($argc >= 4) {
                $a3 = $frame->calledArgs[3]->resolveIndirect();
                if (Variable::TYPE_ARRAY === $a3->type) {
                    foreach ($a3->toArray()->iterateKeyed(true) as $k => $v) {
                        $mimeTypes[\is_int($k) ? (string) $k : (string) $k] = $v;
                    }
                }
            }
            $rewrite = null;
            if ($argc >= 5) {
                $a4 = $frame->calledArgs[4]->resolveIndirect();
                if (Variable::TYPE_OBJECT === $a4->type) {
                    $rewrite = static function () use ($frame, $a4): void {
                        unset($frame, $a4);
                    };
                }
            }
            $requestMethod = null;
            if (null !== $frame->vmContext) {
                $server = $frame->vmContext->getSuperglobal('_SERVER');
                if (null !== $server && Variable::TYPE_ARRAY === $server->type) {
                    $rm = $server->toArray()->find('REQUEST_METHOD');
                    if (null !== $rm && Variable::TYPE_STRING === $rm->type) {
                        $requestMethod = $rm->toString();
                    }
                }
            }
            $scriptPath = PharRunning::resolveScriptPath($frame);
            VmPharStream::webPhar($alias, $index, $f404, $mimeTypes, $rewrite, $scriptPath, $requestMethod);
        });
    }
}
