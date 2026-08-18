<?php

declare(strict_types=1);

/**
 * Issue #31983 — Soap\Sdl payload functions are operation names, not __getFunctions strings.
 *
 * php-src: ext/soap/php_sdl.c (SDL function table keyed by operation name).
 */

use PHPCompiler\ext\soap\SoapClientState;
use PHPCompiler\ext\soap\SoapSdlPayload;

require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/../../ext/soap/VmSoapClient.php';

$state = new SoapClientState();
$state->functions = ['void ping()'];
$state->functionIndex = ['ping' => 'ping'];
$payload = SoapSdlPayload::fromClientState($state);

if (['ping'] !== $payload->functions) {
    fwrite(STDERR, 'expected operation names, got '.var_export($payload->functions, true)."\n");
    exit(1);
}
if (['void ping()'] !== $state->functions) {
    fwrite(STDERR, 'display strings must stay on client state for __getFunctions'."\n");
    exit(1);
}

echo "ok\n";
