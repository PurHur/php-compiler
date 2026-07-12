<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uri;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\EnumSupport;
use PHPCompiler\VM\Variable;

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
        self::registerWhatWgUrl($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    private static function registerExceptions(Context $ctx): void
    {
        $uriException = new ClassEntry('Uri\\UriException');
        if (isset($ctx->classes['exception'])) {
            $uriException->parentLc = 'exception';
        }
        $ctx->classes[VmUri::CLASS_URI_EXCEPTION] = $uriException;

        $uriError = new ClassEntry('Uri\\UriError');
        if (isset($ctx->classes['error'])) {
            $uriError->parentLc = 'error';
        }
        $ctx->classes[VmUri::CLASS_URI_ERROR] = $uriError;

        $invalidUri = new ClassEntry('Uri\\InvalidUriException');
        $invalidUri->parentLc = VmUri::CLASS_URI_EXCEPTION;
        $ctx->classes[VmUri::CLASS_INVALID_URI_EXCEPTION] = $invalidUri;

        $invalidUrl = new ClassEntry('Uri\\WhatWg\\InvalidUrlException');
        $invalidUrl->parentLc = VmUri::CLASS_INVALID_URI_EXCEPTION;
        $ctx->classes[VmUri::CLASS_WHATWG_INVALID_URL] = $invalidUrl;
    }

    private static function registerEnums(Context $ctx): void
    {
        self::registerUnitEnum($ctx, 'Uri\\UriComparisonMode', 'uri\\uricomparisonmode', ['IncludeFragment', 'ExcludeFragment']);
        self::registerUnitEnum($ctx, 'Uri\\Rfc3986\\UriType', 'uri\\rfc3986\\uritype', [
            'AbsolutePathReference',
            'RelativePathReference',
            'NetworkPathReference',
            'Uri',
        ]);
        self::registerUnitEnum($ctx, 'Uri\\Rfc3986\\UriHostType', 'uri\\rfc3986\\urihosttype', [
            'IPv4',
            'IPv6',
            'IPvFuture',
            'RegisteredName',
        ]);
        self::registerUnitEnum($ctx, 'Uri\\WhatWg\\UrlHostType', 'uri\\whatwg\\urlhosttype', [
            'IPv4',
            'IPv6',
            'Domain',
            'Opaque',
            'Empty',
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
        $lc = strtolower($name);
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
        $entry->methods['parse'] = new Rfc3986UriParse();
        $entry->methodVisibility['parse'] = $pubStatic;
        foreach ([
            'gethost' => new Rfc3986UriGetHost(),
            'getpath' => new Rfc3986UriGetPath(),
            'getscheme' => new Rfc3986UriGetScheme(),
            'tostring' => new Rfc3986UriToString(),
        ] as $name => $method) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = $pub;
        }

        $ctx->classes[VmUri::CLASS_RFC3986_URI] = $entry;
    }

    private static function registerWhatWgUrl(Context $ctx): void
    {
        if (isset($ctx->classes[VmUri::CLASS_WHATWG_URL])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $pubStatic = CfgFunc::FLAG_PUBLIC | CfgFunc::FLAG_STATIC;

        $entry = new ClassEntry('Uri\\WhatWg\\Url');
        $entry->methods['parse'] = new WhatWgUrlParse();
        $entry->methodVisibility['parse'] = $pubStatic;
        foreach ([
            'getscheme' => new WhatWgUrlGetScheme(),
            'getasciihost' => new WhatWgUrlGetAsciiHost(),
            'getpath' => new WhatWgUrlGetPath(),
            'toasciistring' => new WhatWgUrlToAsciiString(),
        ] as $name => $method) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = $pub;
        }

        $ctx->classes[VmUri::CLASS_WHATWG_URL] = $entry;
    }
}
