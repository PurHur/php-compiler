<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\inotify\VmInotify;
use PHPCompiler\ext\standard\VmPhpFdStream;
use PHPUnit\Framework\TestCase;

/** @group inotify */
final class InotifyVmTest extends TestCase
{
    public function testModifyEventRoundTrip(): void
    {
        if (!VmInotify::available()) {
            self::markTestSkipped('inotify FFI unavailable');
        }

        $handle = VmInotify::init();
        self::assertNotFalse($handle);

        $path = sys_get_temp_dir().'/inotify-unit-'.getmypid();
        file_put_contents($path, 'a');

        $wd = VmInotify::addWatch($handle, $path, 2);
        self::assertSame(1, $wd);

        $fd = VmPhpFdStream::fdForHandle($handle);
        self::assertNotNull($fd);

        file_put_contents($path, 'b');

        $events = VmInotify::read($handle);
        self::assertIsArray($events);
        self::assertNotEmpty($events);
        self::assertArrayHasKey('mask', $events[0]);

        VmInotify::rmWatch($handle, $wd);
        @unlink($path);
    }
}
