<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\BuiltinInternalArgInfo;
use PHPCompiler\BuiltinParamNames;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** collator_compare()/collator_create() Reflection arginfo tables (#25497). */
final class CollatorCompareReflectionArginfoTest extends TestCase
{
    public function testCollatorCompareReflectionStubTypes(): void
    {
        $fn = 'collator_compare';
        $this->assertSame('int|false', BuiltinInternalArgInfo::returnTypeLabelForFunction($fn));
        $this->assertSame('Collator', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 0));
        $this->assertSame('string', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 1));
        $this->assertSame('string', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 2));

        $object = BuiltinInternalArgInfo::paramInfoForFunction($fn, 0);
        $this->assertNotNull($object);
        $this->assertSame('Collator', $object['type']);
        $this->assertFalse($object['isOptional']);

        $string1 = BuiltinInternalArgInfo::paramInfoForFunction($fn, 1);
        $this->assertNotNull($string1);
        $this->assertSame('string', $string1['type']);
        $this->assertFalse($string1['isOptional']);

        $string2 = BuiltinInternalArgInfo::paramInfoForFunction($fn, 2);
        $this->assertNotNull($string2);
        $this->assertSame('string', $string2['type']);
        $this->assertFalse($string2['isOptional']);

        $this->assertSame(
            ['object', 'string1', 'string2'],
            BuiltinParamNames::forFunction($fn)
        );
        $this->assertSame(
            ['object', 'string1', 'string2'],
            BuiltinParamNames::paramNamesForInternalFunction($fn)
        );
        $this->assertSame(3, BuiltinParamNames::requiredParamCountForInternalFunction($fn));
        $this->assertSame(3, BuiltinParamNames::paramCountForInternalFunction($fn));

        $names = BuiltinParamNames::forFunction($fn);
        $this->assertSame(0, BuiltinParamNames::lookupNamedParamIndex($names, 'object', $fn));
        $this->assertSame(1, BuiltinParamNames::lookupNamedParamIndex($names, 'string1', $fn));
        $this->assertSame(2, BuiltinParamNames::lookupNamedParamIndex($names, 'string2', $fn));
        $this->assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'str1', $fn));
        $this->assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'str2', $fn));
        $this->assertFalse(BuiltinParamNames::lookupNamedParamIndex($names, 'coll', $fn));
    }

    public function testCollatorCreateReflectionStubTypes(): void
    {
        $fn = 'collator_create';
        $this->assertSame('?Collator', BuiltinInternalArgInfo::returnTypeLabelForFunction($fn));
        $this->assertSame('string', BuiltinInternalArgInfo::stubParamTypeOverride($fn, 0));

        $locale = BuiltinInternalArgInfo::paramInfoForFunction($fn, 0);
        $this->assertNotNull($locale);
        $this->assertSame('locale', $locale['name']);
        $this->assertSame('string', $locale['type']);
        $this->assertFalse($locale['isOptional']);

        $this->assertSame(['locale'], BuiltinParamNames::forFunction($fn));
        $this->assertSame(['locale'], BuiltinParamNames::paramNamesForInternalFunction($fn));
        $this->assertSame(1, BuiltinParamNames::requiredParamCountForInternalFunction($fn));
        $this->assertSame(0, BuiltinParamNames::lookupNamedParamIndex(
            BuiltinParamNames::forFunction($fn),
            'locale',
            $fn
        ));
    }

    /**
     * Force-register when host php-intl is absent so named args still run in CI images (#25497).
     */
    public function testNamedCompareAndReflectionViaForcedRegistration(): void
    {
        $runtime = new Runtime();
        \PHPCompiler\ext\intl\BuiltinClasses::registerCollator($runtime->vmContext);
        $runtime->vmContext->declareFunction(new \PHPCompiler\ext\intl\collator_create());
        $runtime->vmContext->declareFunction(new \PHPCompiler\ext\intl\collator_compare());
        $code = <<<'PHP'
<?php
$rf = new ReflectionFunction('collator_compare');
echo 'ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : '(none)', "\n";
foreach ($rf->getParameters() as $p) {
    $t = $p->getType();
    echo ($t ? (string) $t.' ' : ''), '$', $p->getName(), "\n";
}
$cr = new ReflectionFunction('collator_create');
echo 'create_ret=', $cr->hasReturnType() ? (string) $cr->getReturnType() : '(none)', "\n";
$c = Collator::create('en_US');
echo 'pos=', var_export(collator_compare($c, 'a', 'b'), true), "\n";
echo 'named=', var_export(collator_compare(object: $c, string1: 'a', string2: 'b'), true), "\n";
try {
    collator_compare(coll: $c, str1: 'a', str2: 'b');
    echo "legacy_ok\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
$created = collator_create(locale: 'en_US');
echo 'create_named=', $created instanceof Collator ? 'ok' : 'fail', "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'issue_25497.php');
        ob_start();
        $runtime->run($block);
        $this->assertSame(
            "ret=int|false\nCollator \$object\nstring \$string1\nstring \$string2\ncreate_ret=?Collator\npos=-1\nnamed=-1\nUnknown named parameter \$coll\ncreate_named=ok\n",
            ob_get_clean()
        );
    }
}
