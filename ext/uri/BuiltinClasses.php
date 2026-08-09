<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uri;

use PHPCfg\Func as CfgFunc;
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
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\EnumSupport;
use PHPCompiler\VM\ExceptionSupport;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\ThrowableManifest;

/**
 * Register ext/uri builtin classes and enums (php-src ext/uri/php_uri.stub.php; #9051).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        if (!UriExtensionPolicy::advertisesExtension()) {
            return;
        }

        $before = array_keys($ctx->classes);
        self::registerExceptions($ctx);
        self::registerEnums($ctx);
        self::registerRfc3986Uri($ctx);
        self::registerRfc3986UriBuilder($ctx);
        self::registerWhatWgUrl($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    private static function registerExceptions(Context $ctx): void
    {
        $uriException = self::newExceptionFamilyEntry($ctx, 'Uri\\UriException', 'exception');
        $ctx->classes[VmUri::CLASS_URI_EXCEPTION] = $uriException;

        $uriError = self::newErrorFamilyEntry($ctx, 'Uri\\UriError', 'error');
        $ctx->classes[VmUri::CLASS_URI_ERROR] = $uriError;

        $invalidUri = self::newExceptionFamilyEntry($ctx, 'Uri\\InvalidUriException', VmUri::CLASS_URI_EXCEPTION);
        $ctx->classes[VmUri::CLASS_INVALID_URI_EXCEPTION] = $invalidUri;

        $invalidUrl = self::newExceptionFamilyEntry($ctx, 'Uri\\WhatWg\\InvalidUrlException', VmUri::CLASS_INVALID_URI_EXCEPTION);
        $ctx->classes[VmUri::CLASS_WHATWG_INVALID_URL] = $invalidUrl;

        self::registerUrlValidationError($ctx);
    }

    /**
     * Exception-family throwable with zend_exceptions.stub.php slots (ThrowableManifest shape).
     */
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

    /** Error-family throwable slots for Uri\UriError. */
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
        $pub = CfgFunc::FLAG_PUBLIC;
        $prot = CfgFunc::FLAG_PROTECTED;
        $priv = CfgFunc::FLAG_PRIVATE;
        $errorLc = ThrowableManifest::LC_ERROR;

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

        $entry->constructor = new ExceptionConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
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

    private static function registerUrlValidationError(Context $ctx): void
    {
        if (isset($ctx->classes[VmUri::CLASS_WHATWG_URL_VALIDATION_ERROR])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('Uri\\WhatWg\\UrlValidationError');
        $strProto = new Variable(Variable::TYPE_STRING);
        $boolProto = new Variable(Variable::TYPE_BOOLEAN);
        $nullProto = new Variable(Variable::TYPE_NULL);
        $entry->properties[] = new ClassProperty('context', null, $strProto, true, $pub, VmUri::CLASS_WHATWG_URL_VALIDATION_ERROR);
        $entry->properties[] = new ClassProperty('type', null, $nullProto, true, $pub, VmUri::CLASS_WHATWG_URL_VALIDATION_ERROR);
        $entry->properties[] = new ClassProperty('failure', null, $boolProto, true, $pub, VmUri::CLASS_WHATWG_URL_VALIDATION_ERROR);
        $ctor = new WhatWgUrlValidationErrorConstruct();
        $entry->constructor = $ctor;
        $entry->methods['__construct'] = $ctor;
        $entry->methodVisibility['__construct'] = $pub;
        $ctx->classes[VmUri::CLASS_WHATWG_URL_VALIDATION_ERROR] = $entry;
    }

    private static function registerEnums(Context $ctx): void
    {
        self::registerUnitEnum($ctx, 'Uri\\UriComparisonMode', 'uri\\uricomparisonmode', ['IncludeFragment', 'ExcludeFragment']);
        // UriType / UriHostType absent from php-src PHP-8.5 php_uri.stub.php (#28198)
        self::registerUnitEnum($ctx, 'Uri\\WhatWg\\UrlValidationErrorType', VmUri::CLASS_WHATWG_URL_VALIDATION_ERROR_TYPE, [
            'DomainToAscii',
            'DomainToUnicode',
            'DomainInvalidCodePoint',
            'HostInvalidCodePoint',
            'Ipv4EmptyPart',
            'Ipv4TooManyParts',
            'Ipv4NonNumericPart',
            'Ipv4NonDecimalPart',
            'Ipv4OutOfRangePart',
            'Ipv6Unclosed',
            'Ipv6InvalidCompression',
            'Ipv6TooManyPieces',
            'Ipv6MultipleCompression',
            'Ipv6InvalidCodePoint',
            'Ipv6TooFewPieces',
            'Ipv4InIpv6TooManyPieces',
            'Ipv4InIpv6InvalidCodePoint',
            'Ipv4InIpv6OutOfRangePart',
            'Ipv4InIpv6TooFewParts',
            'InvalidUrlUnit',
            'SpecialSchemeMissingFollowingSolidus',
            'MissingSchemeNonRelativeUrl',
            'InvalidReverseSoldius',
            'InvalidCredentials',
            'HostMissing',
            'PortOutOfRange',
            'PortInvalid',
            'FileInvalidWindowsDriveLetter',
            'FileInvalidWindowsDriveLetterHost',
        ]);
    }

    /**
     * @param list<string> $cases
     */
    private static function registerUnitEnum(Context $ctx, string $name, string $lc, array $cases): void
    {
        if (isset($ctx->classes[$lc])) {
            return;
        }

        $entry = new ClassEntry($name);
        $entry->isEnum = true;
        foreach ($cases as $caseName) {
            self::registerUnitEnumCase($entry, $caseName);
        }
        EnumSupport::ensureBuiltinCasesMethod($entry);
        EnumSupport::ensureBuiltinEnumInterfaces($entry);
        $ctx->classes[$lc] = $entry;
        $ctx->enums[$lc] = true;
    }

    private static function registerUnitEnumCase(ClassEntry $enum, string $name): void
    {
        $lc = \PHPCompiler\ClassConstName::key($name);
        $dummy = new Variable();
        $case = EnumCaseSupport::createCase($enum, $name, $dummy);
        $enum->constants[$lc] = $case;
        $enum->enumCaseCanonicalNames[$lc] = $name;
        $enum->enumCases[] = [
            'name' => $name,
            'value' => null,
        ];
    }

    private static function registerRfc3986Uri(Context $ctx): void
    {
        if (isset($ctx->classes[VmUri::CLASS_RFC3986_URI])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $pubStatic = CfgFunc::FLAG_PUBLIC | CfgFunc::FLAG_STATIC;

        $entry = new ClassEntry('Uri\\Rfc3986\\Uri');
        $ctor = new Rfc3986UriConstruct();
        $entry->constructor = $ctor;
        $entry->methods['__construct'] = $ctor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['parse'] = new Rfc3986UriParse();
        $entry->methodVisibility['parse'] = $pubStatic;
        foreach ([
            'gethost' => new Rfc3986UriGetHost(),
            'getrawhost' => new Rfc3986UriGetRawHost(),
            'getpath' => new Rfc3986UriGetPath(),
            'getrawpath' => new Rfc3986UriGetRawPath(),
            'getscheme' => new Rfc3986UriGetScheme(),
            'getrawscheme' => new Rfc3986UriGetRawScheme(),
            'getquery' => new Rfc3986UriGetQuery(),
            'getrawquery' => new Rfc3986UriGetRawQuery(),
            'getfragment' => new Rfc3986UriGetFragment(),
            'getrawfragment' => new Rfc3986UriGetRawFragment(),
            'getport' => new Rfc3986UriGetPort(),
            'getuserinfo' => new Rfc3986UriGetUserInfo(),
            'getrawuserinfo' => new Rfc3986UriGetRawUserInfo(),
            'getusername' => new Rfc3986UriGetUsername(),
            'getrawusername' => new Rfc3986UriGetRawUsername(),
            'getpassword' => new Rfc3986UriGetPassword(),
            'getrawpassword' => new Rfc3986UriGetRawPassword(),
            'tostring' => new Rfc3986UriToString(),
            'torawstring' => new Rfc3986UriToRawString(),
            'withscheme' => new Rfc3986UriWithScheme(),
            'withhost' => new Rfc3986UriWithHost(),
            'withport' => new Rfc3986UriWithPort(),
            'withpath' => new Rfc3986UriWithPath(),
            'withquery' => new Rfc3986UriWithQuery(),
            'withfragment' => new Rfc3986UriWithFragment(),
            'withuserinfo' => new Rfc3986UriWithUserInfo(),
            // getUriType / getHostType absent from php-src PHP-8.5 php_uri.stub.php (#28198)
            'resolve' => new Rfc3986UriResolve(),
            'equals' => new Rfc3986UriEquals(),
            '__serialize' => new Rfc3986UriSerialize(),
            '__unserialize' => new Rfc3986UriUnserialize(),
            '__debuginfo' => new Rfc3986UriDebugInfo(),
        ] as $name => $method) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = $pub;
        }
        $entry->methodNames['__debuginfo'] = '__debugInfo';
        $entry->methodNames['__serialize'] = '__serialize';
        $entry->methodNames['__unserialize'] = '__unserialize';

        $ctx->classes[VmUri::CLASS_RFC3986_URI] = $entry;
    }

    private static function registerRfc3986UriBuilder(Context $ctx): void
    {
        if (isset($ctx->classes[VmUri::CLASS_RFC3986_URI_BUILDER])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('Uri\\Rfc3986\\UriBuilder');
        $ctor = new Rfc3986UriBuilderConstruct();
        $entry->constructor = $ctor;
        $entry->methods['__construct'] = $ctor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach ([
            'reset' => new Rfc3986UriBuilderReset(),
            'setscheme' => new Rfc3986UriBuilderSetScheme(),
            'setuserinfo' => new Rfc3986UriBuilderSetUserInfo(),
            'sethost' => new Rfc3986UriBuilderSetHost(),
            'setport' => new Rfc3986UriBuilderSetPort(),
            'setpath' => new Rfc3986UriBuilderSetPath(),
            'setquery' => new Rfc3986UriBuilderSetQuery(),
            'setfragment' => new Rfc3986UriBuilderSetFragment(),
            'build' => new Rfc3986UriBuilderBuild(),
        ] as $name => $method) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = $pub;
        }

        $ctx->classes[VmUri::CLASS_RFC3986_URI_BUILDER] = $entry;
    }

    private static function registerWhatWgUrl(Context $ctx): void
    {
        if (isset($ctx->classes[VmUri::CLASS_WHATWG_URL])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $pubStatic = CfgFunc::FLAG_PUBLIC | CfgFunc::FLAG_STATIC;

        $entry = new ClassEntry('Uri\\WhatWg\\Url');
        $ctor = new WhatWgUrlConstruct();
        $entry->constructor = $ctor;
        $entry->methods['__construct'] = $ctor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['parse'] = new WhatWgUrlParse();
        $entry->methodVisibility['parse'] = $pubStatic;
        foreach ([
            'getscheme' => new WhatWgUrlGetScheme(),
            'getasciihost' => new WhatWgUrlGetAsciiHost(),
            'getunicodehost' => new WhatWgUrlGetUnicodeHost(),
            'getpath' => new WhatWgUrlGetPath(),
            'getquery' => new WhatWgUrlGetQuery(),
            'getfragment' => new WhatWgUrlGetFragment(),
            'getport' => new WhatWgUrlGetPort(),
            'getusername' => new WhatWgUrlGetUsername(),
            'getpassword' => new WhatWgUrlGetPassword(),
            'toasciistring' => new WhatWgUrlToAsciiString(),
            'tounicodestring' => new WhatWgUrlToUnicodeString(),
            'equals' => new WhatWgUrlEquals(),
            'withquery' => new WhatWgUrlWithQuery(),
            'withfragment' => new WhatWgUrlWithFragment(),
            'withscheme' => new WhatWgUrlWithScheme(),
            'withhost' => new WhatWgUrlWithHost(),
            'withpath' => new WhatWgUrlWithPath(),
            'withport' => new WhatWgUrlWithPort(),
            'withusername' => new WhatWgUrlWithUsername(),
            'withpassword' => new WhatWgUrlWithPassword(),
            // isSpecialScheme / getHostType absent from php-src PHP-8.5 WhatWg\Url stub (#28199)
            'resolve' => new WhatWgUrlResolve(),
            '__serialize' => new WhatWgUrlSerialize(),
            '__unserialize' => new WhatWgUrlUnserialize(),
            '__debuginfo' => new WhatWgUrlDebugInfo(),
        ] as $name => $method) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = $pub;
        }
        $entry->methodNames['__debuginfo'] = '__debugInfo';
        $entry->methodNames['__serialize'] = '__serialize';
        $entry->methodNames['__unserialize'] = '__unserialize';

        $ctx->classes[VmUri::CLASS_WHATWG_URL] = $entry;
    }
}
