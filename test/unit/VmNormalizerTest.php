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
}
