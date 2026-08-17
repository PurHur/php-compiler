<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\intl\UnicodeCanonical;
use PHPCompiler\ext\intl\VmNormalizer;
use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/** @group intl_normalizer */
final class VmNormalizerTest extends TestCase
{
    public function test_normalizer_withheld_without_intl(): void
    {
        $runtime = new Runtime();
        self::assertFalse(VmReflection::functionExists($runtime->vmContext, 'normalizer_normalize'));
        self::assertFalse(VmReflection::functionExists($runtime->vmContext, 'normalizer_is_normalized'));
        self::assertFalse(VmReflection::classExists($runtime->vmContext, 'Normalizer'));
    }

    public function test_nfc_latin1_e_acute(): void
    {
        $composed = "\xC3\xA9";
        $decomposed = "e\xCC\x81";
        self::assertSame('c3a9', bin2hex(VmNormalizer::normalize($decomposed, VmNormalizer::FORM_C)));
        self::assertSame($composed, VmNormalizer::normalize($composed, VmNormalizer::FORM_C));
        self::assertTrue(VmNormalizer::isNormalized($composed, VmNormalizer::FORM_C));
        self::assertFalse(VmNormalizer::isNormalized($decomposed, VmNormalizer::FORM_C));
    }

    public function test_nfd_round_trip(): void
    {
        $composed = "\xC3\xA9";
        $nfd = UnicodeCanonical::normalizeNfd($composed);
        self::assertSame("e\xCC\x81", $nfd);
        self::assertTrue(VmNormalizer::isNormalized($nfd, VmNormalizer::FORM_D));
    }

    public function test_invalid_form_value_error(): void
    {
        $this->expectException(\ValueError::class);
        VmNormalizer::normalize('x', 99);
    }

    /**
     * php-src normalizer.stub.php — $string/$form + named string:; input: rejected (#25586).
     *
     * Force-registers when host php-intl is absent (Docker #22691).
     */
    public function test_normalize_reflection_named_string_via_forced_registration(): void
    {
        $runtime = new Runtime();
        if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::advertisesNormalizer()) {
            \PHPCompiler\ext\intl\BuiltinClasses::registerNormalizer($runtime->vmContext);
            $runtime->vmContext->declareFunction(new \PHPCompiler\ext\intl\normalizer_normalize());
        }
        $code = <<<'PHP'
<?php
$rf = new ReflectionFunction('normalizer_normalize');
echo 'arity=', $rf->getNumberOfParameters(), ' req=', $rf->getNumberOfRequiredParameters(), "\n";
echo 'ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : '(none)', "\n";
foreach ($rf->getParameters() as $p) {
    $t = $p->getType();
    echo '  ', ($t ? (string) $t.' ' : ''), '$', $p->getName();
    if ($p->isOptional()) {
        echo ' OPT';
        if ($p->isDefaultValueAvailable()) {
            echo '=', json_encode($p->getDefaultValue());
        }
    } else {
        echo ' REQ';
    }
    echo "\n";
}
$s = "e\u{0301}";
echo 'named_string=', bin2hex(normalizer_normalize(string: $s)), "\n";
echo 'named_form=', bin2hex(normalizer_normalize(string: $s, form: Normalizer::FORM_C)), "\n";
echo 'positional=', bin2hex(normalizer_normalize($s)), "\n";
try {
    normalizer_normalize(input: $s);
    echo "legacy_input accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        $block = $runtime->parseAndCompile($code, 'normalizer_normalize_reflection_25586.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "arity=2 req=1\n"
            ."ret=string|false\n"
            ."  string \$string REQ\n"
            ."  int \$form OPT=16\n"
            ."named_string=c3a9\n"
            ."named_form=c3a9\n"
            ."positional=c3a9\n"
            ."Unknown named parameter \$input\n",
            ob_get_clean()
        );
    }
}
