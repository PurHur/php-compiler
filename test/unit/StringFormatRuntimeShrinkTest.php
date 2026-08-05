<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SprintfJitHelper;
use PHPCompiler\ext\standard\VmSprintf;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/**
 * sprintf/printf/number_format JIT: always NestedJIT SprintfJitHelper (#9131, #20841).
 */
final class StringFormatRuntimeShrinkTest extends TestCase
{
    public function testStringFormatUsesSprintfJitHelperNotLlvmMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFormat.php');
        $this->assertStringContainsString('SprintfJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('PackArgvSerialize::ensureLinked', $source);
        $this->assertStringNotContainsString('StringFormatJit', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('StringFormatInventoryStubs', $source);
        $this->assertStringNotContainsString('ensureDeferredStubsForInventoryEmit', $source);
        $this->assertStringNotContainsString('COMPILED_PATHS', $source);
        $this->assertLessThan(340, \substr_count($source, "\n") + 1);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringFormatInventoryStubs.php');
    }

    public function testStandaloneUsesSamePhpBridgeAsEmbed(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFormat.php');
        $this->assertStringContainsString('ensureStandaloneBodies', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('sprintf_bridge_entry', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('StringFormatInventoryStubs', $source);
    }

    public function testSprintfJitHelperIsNestedJitSafe(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/SprintfJitHelper.php');
        $this->assertStringNotContainsString('new Variable', $source);
        $this->assertStringNotContainsString('new HashTable', $source);
        $this->assertStringNotContainsString('VmSprintf::', $source);
        $this->assertStringNotContainsString('VmString::', $source);
        $this->assertStringContainsString('byteOrd', $source);
        // NestedJIT mishandles `$packed[$i + 1]` on heap blobs (#23871) — readers must ++ only.
        $this->assertDoesNotMatchRegularExpression(
            '/\$n\s*\|\=\s*self::byteOrd\(\$packed\[\$i\s*\+\s*1\]\)/',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$packed\[\$offset\s*\+\s*1\s*\+\s*\$i\]/',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$packed\[\$cursor\s*\+\s*\$i\]/',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$packed\[\$offset\s*\+\s*9\s*\+\s*\$i\]/',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$cursor\s*\+=\s*\$size/',
            $source
        );
    }

    public function testSprintfJitHelperMatchesVmSprintfForPercent03d(): void
    {
        $blob = "\x01".\pack('q', 7);
        $this->assertSame('007', SprintfJitHelper::sprintfArgv('%03d', $blob));
        $v = new Variable();
        $v->int(7);
        $this->assertSame(VmSprintf::format('%03d', [$v]), SprintfJitHelper::sprintfArgv('%03d', $blob));
    }


    public function testSprintfJitHelperZeroPadAndCustomPadMatchVmSprintf(): void
    {
        $blobInt = "\x01".\pack('q', 42);
        $this->assertSame('00042', SprintfJitHelper::sprintfArgv('%05d', $blobInt));
        $v = new Variable();
        $v->int(42);
        $this->assertSame(VmSprintf::format('%05d', [$v]), SprintfJitHelper::sprintfArgv('%05d', $blobInt));
        $blobStr = "\x04".\pack('q', 1).'x';
        $this->assertSame('#########x', SprintfJitHelper::sprintfArgv("%'#10s", $blobStr));
        $x = new Variable();
        $x->string('x');
        $this->assertSame(VmSprintf::format("%'#10s", [$x]), SprintfJitHelper::sprintfArgv("%'#10s", $blobStr));
    }

    public function testSprintfJitHelperCoercesEmptyArrayForPercentD(): void
    {
        $emptyArrayBlob = "\x05";
        $emptyArrayVar = new Variable();
        $emptyArrayVar->array(new HashTable());
        $this->assertSame('0', SprintfJitHelper::sprintfArgv('%d', $emptyArrayBlob));
        $this->assertSame(
            VmSprintf::format('%d', [$emptyArrayVar]),
            SprintfJitHelper::sprintfArgv('%d', $emptyArrayBlob)
        );
    }

    public function testSprintfJitHelperCoercesNullForPercentSAndD(): void
    {
        $nullBlob = "\x00";
        $nullVar = new Variable();
        $nullVar->null();
        $this->assertSame('', SprintfJitHelper::sprintfArgv('%s', $nullBlob));
        $this->assertSame('0', SprintfJitHelper::sprintfArgv('%d', $nullBlob));
        $this->assertSame('<>', SprintfJitHelper::sprintfArgv('<%s>', $nullBlob));
        $this->assertSame('|0', SprintfJitHelper::sprintfArgv('%s|%d', $nullBlob.$nullBlob));
        $this->assertSame(
            VmSprintf::format('%s', [$nullVar]),
            SprintfJitHelper::sprintfArgv('%s', $nullBlob)
        );
        $this->assertSame(
            VmSprintf::format('%d', [$nullVar]),
            SprintfJitHelper::sprintfArgv('%d', $nullBlob)
        );
        $this->assertSame(
            VmSprintf::format('<%s>', [$nullVar]),
            SprintfJitHelper::sprintfArgv('<%s>', $nullBlob)
        );
        $this->assertSame(
            VmSprintf::format('%s|%d', [$nullVar, $nullVar]),
            SprintfJitHelper::sprintfArgv('%s|%d', $nullBlob.$nullBlob)
        );
    }

    public function testSprintfJitHelperSequentialMultiArgDecimals(): void
    {
        $blob = "\x01".\pack('q', 2)."\x01".\pack('q', 4);
        $this->assertSame('2-4', SprintfJitHelper::sprintfArgv('%d-%d', $blob));
        $this->assertSame('2 4', SprintfJitHelper::sprintfArgv('%d %d', $blob));
        $blobMixed = "\x01".\pack('q', 9)."\x04".\pack('q', 3).'web';
        $this->assertSame('id=9 name=web', SprintfJitHelper::sprintfArgv('id=%d name=%s', $blobMixed));
        $this->assertSame('ok=%', SprintfJitHelper::sprintfArgv('ok=%%', ''));
    }

    public function testVmSprintfCustomPadMatchesZendShapes(): void
    {
        $x = new Variable();
        $x->string('x');
        $seven = new Variable();
        $seven->int(7);
        $this->assertSame('*******************x', VmSprintf::format("%'*20s", [$x]));
        $this->assertSame('*********7', VmSprintf::format("%'*10d", [$seven]));
        $this->assertSame('*********x', VmSprintf::format("%1$'*10s", [$x]));
        $this->assertSame('x*********', VmSprintf::format("%-'*10s", [$x]));
    }

    public function testSprintfJitHelperPositionalStarMatchesVmSprintf(): void
    {
        // Packed argv: TAG_LONG(5), TAG_STRING("z") — %2$*1$s
        $blob = "\x01".\pack('q', 5)."\x04".\pack('q', 1).'z';
        $this->assertSame('    z', SprintfJitHelper::sprintfArgv('%2$*1$s', $blob));
        $w = new Variable();
        $w->int(5);
        $s = new Variable();
        $s->string('z');
        $this->assertSame(
            VmSprintf::format('%2$*1$s', [$w, $s]),
            SprintfJitHelper::sprintfArgv('%2$*1$s', $blob)
        );

        // TAG_STRING("abcdef"), TAG_LONG(3) — %1$.*2$s
        $blob2 = "\x04".\pack('q', 6).'abcdef'."\x01".\pack('q', 3);
        $this->assertSame('abc', SprintfJitHelper::sprintfArgv('%1$.*2$s', $blob2));

        // TAG_LONG(8), TAG_LONG(3), TAG_STRING("abcdef") — %3$*1$.*2$s
        $blob3 = "\x01".\pack('q', 8)."\x01".\pack('q', 3)."\x04".\pack('q', 6).'abcdef';
        $this->assertSame('     abc', SprintfJitHelper::sprintfArgv('%3$*1$.*2$s', $blob3));
    }

    public function testSprintfJitHelperNumberFormatBasics(): void
    {
        $this->assertSame('1,234.50', SprintfJitHelper::numberFormat(1234.5, 2, '.', ','));
        $this->assertSame('3', SprintfJitHelper::numberFormat(2.5, 0, '.', '', 1));
        $this->assertSame('2', SprintfJitHelper::numberFormat(2.5, 0, '.', '', 7));
    }

    public function testSpineBundleIncludesStringFormatPhpJitPath(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('SprintfJitHelper.php', $spine);
        $this->assertStringContainsString('StringFormat.php', $spine);
        $this->assertStringNotContainsString('StringFormatInventoryStubs.php', $spine);
    }

    public function testEmitHelperRuntimeNoLongerMarksSprintfUnsafe(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../script/emit-helper-runtime-object.php');
        $this->assertStringNotContainsString("'/ext/standard/SprintfJitHelper.php' => true", $source);
    }
}
