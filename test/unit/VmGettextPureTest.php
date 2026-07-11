<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\gettext\VmGettextNative;
use PHPCompiler\ext\gettext\VmGettextPure;
use PHPUnit\Framework\TestCase;

/** gettext VM path uses VmGettextPure MO reader, not libintl FFI (#8952). */
final class VmGettextPureTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        $this->fixtureRoot = sys_get_temp_dir().'/phpc_gettext_pure_'.getmypid();
        if (is_dir($this->fixtureRoot)) {
            self::removeTree($this->fixtureRoot);
        }
        mkdir($this->fixtureRoot.'/LC_MESSAGES', 0777, true);
        file_put_contents(
            $this->fixtureRoot.'/LC_MESSAGES/messages.mo',
            VmGettextPure::buildMoFile(['Hello' => 'Hola', 'World' => 'Mundo'])
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->fixtureRoot)) {
            self::removeTree($this->fixtureRoot);
        }
    }

    public function testAvailableWithoutLibintlFfi(): void
    {
        $this->assertTrue(VmGettextNative::available());
        $source = (string) file_get_contents(__DIR__.'/../../ext/gettext/VmGettextNative.php');
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('extension_loaded(\'ffi\')', $source);
        $this->assertStringContainsString('VmGettextPure', $source);
    }

    public function testGettextTranslatesBoundCatalog(): void
    {
        VmGettextNative::bindtextdomain('messages', $this->fixtureRoot);
        VmGettextNative::textdomain('messages');

        $this->assertSame('Hola', VmGettextNative::gettext('Hello'));
        $this->assertSame('Mundo', VmGettextNative::dgettext('messages', 'World'));
        $this->assertSame('missing', VmGettextNative::gettext('missing'));
    }

    public function testParseMoRoundTrip(): void
    {
        $mo = VmGettextPure::buildMoFile(['a' => 'b', "one\0two" => "ein\0zwei"]);
        $catalog = VmGettextPure::parseMo($mo);
        $this->assertIsArray($catalog);
        $this->assertSame('b', $catalog['a']);
        $this->assertSame("ein\0zwei", $catalog["one\0two"]);
    }

    private static function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir.'/'.$entry;
            if (is_dir($path)) {
                self::removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
