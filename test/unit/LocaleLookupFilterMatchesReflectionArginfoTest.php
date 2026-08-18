<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\BuiltinInternalArgInfo;
use PHPCompiler\BuiltinParamNames;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** locale_lookup()/locale_filter_matches() Reflection arginfo tables (#25198). */
final class LocaleLookupFilterMatchesReflectionArginfoTest extends TestCase
{
    public function testLocaleLookupReflectionStubTypes(): void
    {
        $fn = 'locale_lookup';
        $this->assertSame('?string', BuiltinInternalArgInfo::returnTypeLabelForFunction($fn));
        $this->assertSame('array', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 0));
        $this->assertSame('string', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 1));
        $this->assertSame('bool', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 2));
        $this->assertSame('?string', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 3));

        $languageTag = BuiltinInternalArgInfo::paramInfoForFunction($fn, 0);
        $this->assertNotNull($languageTag);
        $this->assertSame('array', $languageTag['type']);
        $this->assertFalse($languageTag['isOptional']);

        $defaultLocale = BuiltinInternalArgInfo::paramInfoForFunction($fn, 3);
        $this->assertNotNull($defaultLocale);
        $this->assertSame('?string', $defaultLocale['type']);
        $this->assertTrue($defaultLocale['isOptional']);

        $this->assertSame(
            ['languageTag', 'locale', 'canonicalize=', 'defaultLocale='],
            BuiltinParamNames::forFunction($fn)
        );
        $this->assertSame(
            ['languageTag', 'locale', 'canonicalize=', 'defaultLocale='],
            BuiltinParamNames::paramNamesForInternalFunction($fn)
        );
        $this->assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction($fn));
        $this->assertSame(4, BuiltinParamNames::paramCountForInternalFunction($fn));

        $names = BuiltinParamNames::forFunction($fn);
        $this->assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'languageTag', $fn));
        $this->assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'locale', $fn));
        $this->assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'canonicalize', $fn));
        $this->assertSame(3, BuiltinParamNames::lookupNamedParamIndex($names, 'defaultLocale', $fn));
        $this->assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'langtag', $fn));
        $this->assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'default', $fn));
    }

    public function testLocaleFilterMatchesReflectionStubTypes(): void
    {
        $fn = 'locale_filter_matches';
        $this->assertSame('?bool', BuiltinInternalArgInfo::returnTypeLabelForFunction($fn));
        $this->assertSame('string', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 0));
        $this->assertSame('string', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 1));
        $this->assertSame('bool', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 2));

        $this->assertSame(
            ['languageTag', 'locale', 'canonicalize='],
            BuiltinParamNames::forFunction($fn)
        );
        $this->assertSame(2, BuiltinParamNames::requiredParamCountForInternalFunction($fn));
        $this->assertSame(3, BuiltinParamNames::paramCountForInternalFunction($fn));

        $names = BuiltinParamNames::forFunction($fn);
        $this->assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'languageTag', $fn));
        $this->assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'langtag', $fn));
    }

    public function testLocaleClassMethodStubNames(): void
    {
        $this->assertSame(
            ['languageTag', 'locale', 'canonicalize=', 'defaultLocale='],
            BuiltinParamNames::forClassMethod('Locale::lookup')
        );
        $this->assertSame(
            ['languageTag', 'locale', 'canonicalize='],
            BuiltinParamNames::forClassMethod('Locale::filterMatches')
        );
        $this->assertSame(
            ['languageTag', 'locale', 'canonicalize=', 'defaultLocale='],
            BuiltinParamNames::paramNamesForInternalFunction('Locale::lookup')
        );
        $this->assertSame(
            0,
            BuiltinParamNames::lookupNamedParamIndex(
                BuiltinParamNames::forClassMethod('Locale::lookup'),
                'languageTag',
                'Locale::lookup'
            )
        );
        $this->assertFalse(BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forClassMethod('Locale::lookup'),
            'langtag',
            'Locale::lookup'
        ));
        $this->assertFalse(BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forClassMethod('Locale::lookup'),
            'default',
            'Locale::lookup'
        ));
    }

    /**
     * Force-register when host php-intl is absent so named args still run in CI images (#25198).
     */
    public function testNamedLookupAndReflectionViaForcedRegistration(): void
    {
        $runtime = new Runtime();
        \PHPCompiler\ext\intl\BuiltinClasses::registerLocale($runtime->vmContext);
        $runtime->vmContext->declareFunction(new \PHPCompiler\ext\intl\locale_lookup());
        $runtime->vmContext->declareFunction(new \PHPCompiler\ext\intl\locale_filter_matches());
        $code = <<<'PHP'
<?php
$rf = new ReflectionFunction('locale_lookup');
echo 'ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : '(none)', "\n";
foreach ($rf->getParameters() as $p) {
    $t = $p->getType();
    echo ($t ? (string) $t.' ' : ''), '$', $p->getName(), "\n";
}
$ff = new ReflectionFunction('locale_filter_matches');
echo 'filter_ret=', $ff->hasReturnType() ? (string) $ff->getReturnType() : '(none)', "\n";
echo 'lookup_pos=', locale_lookup(['de-DE', 'de'], 'de-CH', true, 'en'), "\n";
echo 'lookup_named=', locale_lookup(
    languageTag: ['de-DE', 'de'],
    locale: 'de-CH',
    canonicalize: true,
    defaultLocale: 'en'
), "\n";
try {
    locale_lookup(langtag: ['de-DE'], locale: 'de', default: 'en');
    echo "legacy_lookup_ok\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
echo 'filter_named=', var_export(
    locale_filter_matches(languageTag: 'de-DE', locale: 'de', canonicalize: false),
    true
), "\n";
try {
    locale_filter_matches(langtag: 'de-DE', locale: 'de');
    echo "legacy_filter_ok\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
echo 'oop_named=', Locale::lookup(
    languageTag: ['de-DE', 'de'],
    locale: 'de-CH',
    canonicalize: true,
    defaultLocale: 'en'
), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'issue_25198.php');
        ob_start();
        $runtime->run($block);
        $this->assertSame(
            "ret=?string\n"
            ."array \$languageTag\n"
            ."string \$locale\n"
            ."bool \$canonicalize\n"
            ."?string \$defaultLocale\n"
            ."filter_ret=?bool\n"
            ."lookup_pos=de\n"
            ."lookup_named=de\n"
            ."Unknown named parameter \$langtag\n"
            ."filter_named=true\n"
            ."Unknown named parameter \$langtag\n"
            ."oop_named=de\n",
            ob_get_clean()
        );
    }
}
