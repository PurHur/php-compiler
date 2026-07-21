<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Guard script/fastcgi-smoke.sh (#1899).
 */
final class FastCgiSmokeScriptTest extends TestCase
{
    public function testFastcgiSmokeScriptDocumentsGateAndFilter(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/fastcgi-smoke.sh');
        $this->assertStringContainsString('FASTCGI_SMOKE_GATE', $body);
        $this->assertStringContainsString('FastCgiRecordTest|FastCgiTest', $body);
        $this->assertStringContainsString('PHP_COMPILER_SKIP_SERVE_TESTS', $body);
        $this->assertStringContainsString('FASTCGI_WEB_AOT_SMOKE_GATE=0', $body);
        $this->assertStringContainsString('#1899', $body);
    }
}
