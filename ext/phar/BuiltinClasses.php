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
            && isset($ctx->classes[VmPhar::CLASS_LC]->methods['addfromstring'])) {
            return;
        }

        $pubStatic = CfgFunc::FLAG_PUBLIC | CfgFunc::FLAG_STATIC;
        $entry = $ctx->classes[VmPhar::CLASS_LC] ?? new ClassEntry('Phar');
        $entry->isInternal = true;

        $entry->methods['running'] = new PharRunning();
        $entry->methodVisibility['running'] = $pubStatic;
        $entry->methodNames['running'] = 'running';

        $entry->methods['canwrite'] = new PharCanWrite();
        $entry->methodVisibility['canwrite'] = $pubStatic;
        $entry->methodNames['canwrite'] = 'canWrite';

        $entry->methods['cancompress'] = new PharCanCompress();
        $entry->methodVisibility['cancompress'] = $pubStatic;
        $entry->methodNames['cancompress'] = 'canCompress';

        $entry->methods['apiversion'] = new PharApiVersion();
        $entry->methodVisibility['apiversion'] = $pubStatic;
        $entry->methodNames['apiversion'] = 'apiVersion';

        $entry->methods['isvalidpharfilename'] = new PharIsValidPharFilename();
        $entry->methodVisibility['isvalidpharfilename'] = $pubStatic;
        $entry->methodNames['isvalidpharfilename'] = 'isValidPharFilename';

        foreach ([
            'none' => VmPhar::COMPRESSED_NONE,
            'gz' => VmPhar::COMPRESSED_GZ,
            'bz2' => VmPhar::COMPRESSED_BZ2,
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
