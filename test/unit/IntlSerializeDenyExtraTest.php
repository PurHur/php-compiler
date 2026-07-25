<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\intl\VmBreakIterator;
use PHPCompiler\ext\intl\VmIntlTimeZone;
use PHPCompiler\ext\intl\VmSpoofchecker;
use PHPCompiler\ext\intl\VmTransliterator;
use PHPCompiler\ext\intl\VmUConverter;
use PHPCompiler\ext\standard\VmSerialize;
use PHPCompiler\Runtime;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** @covers issue #23136 */
final class IntlSerializeDenyExtraTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: callable}>
     */
    public static function deniedClassProvider(): array
    {
        return [
            [VmTransliterator::CLASS_LC, static fn ($ctx) => VmTransliterator::registerClass($ctx)],
            [VmSpoofchecker::CLASS_LC, static fn ($ctx) => VmSpoofchecker::registerClass($ctx)],
            [VmUConverter::CLASS_LC, static fn ($ctx) => VmUConverter::registerClass($ctx)],
            [VmIntlTimeZone::CLASS_LC, static fn ($ctx) => VmIntlTimeZone::registerClass($ctx)],
            [VmBreakIterator::CLASS_LC, static fn ($ctx) => VmBreakIterator::registerClass($ctx)],
            [VmBreakIterator::RULE_BASED_LC, static fn ($ctx) => VmBreakIterator::registerClass($ctx)],
            [VmBreakIterator::PARTS_LC, static fn ($ctx) => VmBreakIterator::registerClass($ctx)],
        ];
    }

    /** @dataProvider deniedClassProvider */
    public function testSerializeOfExtraIntlFamilyThrows(string $classLc, callable $register): void
    {
        $runtime = new Runtime();
        $register($runtime->vmContext);
        $this->assertArrayHasKey($classLc, $runtime->vmContext->classes);
        $display = $runtime->vmContext->classes[$classLc]->name;
        $object = new ObjectEntry($runtime->vmContext->classes[$classLc]);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Serialization of '{$display}' is not allowed");
        VmSerialize::serializeValue($runtime->vmContext, $var);
    }
}
