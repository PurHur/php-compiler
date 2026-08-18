<?php

declare(strict_types=1);

namespace PHPCompiler\ext\soap;

/**
 * Parsed WSDL/SDL snapshot carried by Soap\Sdl (php-src soap_sdl_object->sdl; #23905).
 *
 * Opaque to userland; PHP-in-PHP store keyed by ObjectEntry id in {@see VmSoapOpaque}.
 */
final class SoapSdlPayload
{
    public ?string $wsdl = null;

    public string $location = '';

    public string $uri = '';

    public int $style = SoapConstants::SOAP_RPC;

    public int $use = SoapConstants::SOAP_ENCODED;

    /**
     * Operation names (php-src php_sdl.c function table keys, not function_to_string; #31983).
     *
     * {@see SoapClientState::$functions} keeps __getFunctions display strings (`void ping()`).
     *
     * @var list<string>
     */
    public array $functions = [];

    /** @var array<string, string> */
    public array $functionIndex = [];

    /** @var list<string> */
    public array $types = [];

    /** @var array<string, string> */
    public array $elementTypes = [];

    /** @var array<string, array<string, string>> */
    public array $operationOutputParts = [];

    /** @var array<string, array<string, string>> */
    public array $operationInputParts = [];

    /** @var array<string, array<string, string>> */
    public array $complexTypeFields = [];

    public static function fromClientState(SoapClientState $state): self
    {
        $payload = new self();
        $payload->wsdl = $state->wsdl;
        $payload->location = $state->location;
        $payload->uri = $state->uri;
        $payload->style = $state->style;
        $payload->use = $state->use;
        // php-src php_sdl.c — parsed SDL functions keyed by operation name (#31983).
        $payload->functions = \array_values($state->functionIndex);
        $payload->functionIndex = $state->functionIndex;
        $payload->types = $state->types;
        $payload->elementTypes = $state->elementTypes;
        $payload->operationOutputParts = $state->operationOutputParts;
        $payload->operationInputParts = $state->operationInputParts;
        $payload->complexTypeFields = $state->complexTypeFields;

        return $payload;
    }
}
