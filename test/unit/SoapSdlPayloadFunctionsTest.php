<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\soap\SoapClientState;
use PHPCompiler\ext\soap\SoapSdlPayload;
use PHPUnit\Framework\TestCase;

/**
 * Soap\Sdl snapshot stores WSDL operation names, not __getFunctions display strings (#31983).
 *
 * php-src: ext/soap/php_sdl.c function table keyed by operation name.
 */
final class SoapSdlPayloadFunctionsTest extends TestCase
{
    public function testFromClientStateUsesOperationNamesNotDisplayStrings(): void
    {
        // SoapClientState lives in VmSoapClient.php (not a PSR-4 leaf file).
        require_once dirname(__DIR__, 2).'/ext/soap/VmSoapClient.php';

        $state = new SoapClientState();
        $state->wsdl = '/tmp/s.wsdl';
        $state->location = 'http://127.0.0.1/';
        $state->functions = ['void ping()', 'string echo(string $x)'];
        $state->functionIndex = [
            'ping' => 'ping',
            'echo' => 'echo',
        ];

        $payload = SoapSdlPayload::fromClientState($state);

        self::assertSame(['ping', 'echo'], $payload->functions);
        self::assertSame(['void ping()', 'string echo(string $x)'], $state->functions);
        self::assertSame($state->functionIndex, $payload->functionIndex);
        self::assertSame('/tmp/s.wsdl', $payload->wsdl);
        self::assertSame('http://127.0.0.1/', $payload->location);
    }
}
