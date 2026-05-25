<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * init-miniwebapp template parity (issues #695, #1778, #1835).
 */
final class InitMiniwebappParityTest extends TestCase
{
    public function testInitMiniwebappParityPassesOnMaster(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg($root.'/script/check-init-miniwebapp-parity.sh').' 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function testTemplateReadmeHasNoStale764BlockerWording(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2).'/templates/init-miniwebapp/README.md');
        $stale = [
            'tracked in #764',
            'tracked in [#764]',
            'blocked #764',
            'empty stdout until',
        ];
        foreach ($stale as $phrase) {
            $this->assertStringNotContainsString(
                $phrase,
                $readme,
                'templates/init-miniwebapp/README.md should not use stale #764 blocker wording'
            );
        }
        $this->assertMatchesRegularExpression('/#764.*closed/i', $readme);
        $this->assertMatchesRegularExpression('/AOT execute.*✅|execute ✅/s', $readme);
    }
}
