<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\ThrowableManifest;
use PHPCompiler\VM\Builtin\ExceptionConstruct;
use PHPCompiler\VM\Builtin\ExceptionGetCode;
use PHPCompiler\VM\Builtin\ExceptionGetFile;
use PHPCompiler\VM\Builtin\ExceptionGetLine;
use PHPCompiler\VM\Builtin\ExceptionGetMessage;
use PHPCompiler\VM\Builtin\ExceptionGetPrevious;
use PHPCompiler\VM\Builtin\ExceptionGetTrace;
use PHPCompiler\VM\Builtin\ExceptionGetTraceAsString;
use PHPCompiler\VM\Builtin\ExceptionToString;
use PHPCompiler\VM\Builtin\ExceptionWakeup;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ExceptionSupport;
use PHPCompiler\VM\Variable;

/**
 * Register Filter\* exception classes under PROFILE≥8.5 (php-src filter.stub.php; #28131).
 */
final class BuiltinClasses
{
    public const CLASS_FILTER_EXCEPTION = 'filter\\filterexception';
    public const CLASS_FILTER_FAILED_EXCEPTION = 'filter\\filterfailedexception';

    public static function register(Context $ctx): void
    {
        if (!CompilerVersion::supportsFilterThrowOnFailure()) {
            return;
        }

        $before = array_keys($ctx->classes);
        self::registerExceptions($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    private static function registerExceptions(Context $ctx): void
    {
        $base = self::newExceptionFamilyEntry($ctx, 'Filter\\FilterException', 'exception');
        $ctx->classes[self::CLASS_FILTER_EXCEPTION] = $base;

        $failed = self::newExceptionFamilyEntry(
            $ctx,
            'Filter\\FilterFailedException',
            self::CLASS_FILTER_EXCEPTION
        );
        $ctx->classes[self::CLASS_FILTER_FAILED_EXCEPTION] = $failed;
    }

    private static function newExceptionFamilyEntry(Context $ctx, string $name, string $parentLc): ClassEntry
    {
        $entry = new ClassEntry($name);
        $entry->isInternal = true;
        if (isset($ctx->classes[$parentLc])) {
            $entry->parentLc = $parentLc;
        } elseif (isset($ctx->classes['exception'])) {
            $entry->parentLc = 'exception';
        }

        $strProto = new Variable(Variable::TYPE_STRING);
        $intProto = new Variable(Variable::TYPE_INTEGER);
        $nullProto = new Variable(Variable::TYPE_NULL);
        $arrayProto = new Variable(Variable::TYPE_ARRAY);
        $emptyTrace = new Variable();
        $emptyTrace->newArray();
        $emptyString = new Variable(Variable::TYPE_STRING);
        $emptyString->string('');
        $pub = CfgFunc::FLAG_PUBLIC;
        $prot = CfgFunc::FLAG_PROTECTED;
        $priv = CfgFunc::FLAG_PRIVATE;
        $exceptionLc = ThrowableManifest::LC_EXCEPTION;

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
            $exceptionLc
        );
        $entry->properties[] = new ClassProperty(
            ExceptionSupport::PROP_TRACE,
            $emptyTrace,
            $arrayProto,
            false,
            $priv,
            $exceptionLc
        );
        $entry->properties[] = new ClassProperty(
            ExceptionSupport::PROP_STRING,
            $emptyString,
            $strProto,
            false,
            $priv,
            $exceptionLc
        );

        $entry->constructor = new ExceptionConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['__wakeup'] = new ExceptionWakeup();
        $entry->methodVisibility['__wakeup'] = $pub;
        foreach (
            [
                'getmessage' => new ExceptionGetMessage(),
                'getcode' => new ExceptionGetCode(),
                'getfile' => new ExceptionGetFile(),
                'getline' => new ExceptionGetLine(),
                'getprevious' => new ExceptionGetPrevious(),
                'gettrace' => new ExceptionGetTrace(),
                'gettraceasstring' => new ExceptionGetTraceAsString(),
                '__tostring' => new ExceptionToString(),
            ] as $methodName => $method
        ) {
            $entry->methods[$methodName] = $method;
            $entry->methodVisibility[$methodName] = $pub;
        }

        return $entry;
    }
}
