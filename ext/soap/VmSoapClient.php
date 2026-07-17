<?php

declare(strict_types=1);

namespace PHPCompiler\ext\soap;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmJson;

/**
 * SoapClient VM class — v1 local WSDL + file/HTTP transport (php-src ext/soap/soap.c; #20037, #20183).
 *
 * Fixture mode: options['location'] may be a local filesystem path or file:// URL; __doRequest
 * returns that file's contents (no network). HTTP locations use host file_get_contents POST.
 * With options['trace'], __getLastRequestHeaders / __getLastResponseHeaders capture HTTP header blocks.
 */
final class VmSoapClient
{
    public const CLASS_LC = 'soapclient';

    /** @var array<int, SoapClientState> */
    private static array $store = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && isset($ctx->classes[self::CLASS_LC]->methods['__soapcall'])) {
            return;
        }

        $entry = $ctx->classes[self::CLASS_LC] ?? new ClassEntry('SoapClient');
        $entry->isInternal = true;

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry->constructor = new SoapClientConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        $methods = [
            '__soapcall' => new SoapClientSoapCall(),
            '__dorequest' => new SoapClientDoRequest(),
            '__getfunctions' => new SoapClientGetFunctions(),
            '__gettypes' => new SoapClientGetTypes(),
            '__getlastrequest' => new SoapClientGetLastRequest(),
            '__getlastresponse' => new SoapClientGetLastResponse(),
            '__getlastrequestheaders' => new SoapClientGetLastRequestHeaders(),
            '__getlastresponseheaders' => new SoapClientGetLastResponseHeaders(),
            '__setcookie' => new SoapClientSetCookie(),
            '__getcookies' => new SoapClientGetCookies(),
            '__call' => new SoapClientCall(),
        ];
        foreach ($methods as $name => $method) {
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = $pub;
        }
        $entry->methodNames['__soapcall'] = '__soapCall';
        $entry->methodNames['__dorequest'] = '__doRequest';
        $entry->methodNames['__getfunctions'] = '__getFunctions';
        $entry->methodNames['__gettypes'] = '__getTypes';
        $entry->methodNames['__getlastrequest'] = '__getLastRequest';
        $entry->methodNames['__getlastresponse'] = '__getLastResponse';
        $entry->methodNames['__getlastrequestheaders'] = '__getLastRequestHeaders';
        $entry->methodNames['__getlastresponseheaders'] = '__getLastResponseHeaders';
        $entry->methodNames['__setcookie'] = '__setCookie';
        $entry->methodNames['__getcookies'] = '__getCookies';

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function initObject(ObjectEntry $object, ?string $wsdl, array $options, Context $ctx): void
    {
        $state = new SoapClientState();
        $state->wsdl = $wsdl;
        $state->options = $options;
        $state->location = isset($options['location']) ? (string) $options['location'] : '';
        $state->uri = isset($options['uri']) ? (string) $options['uri'] : '';
        $state->trace = !empty($options['trace']);
        $state->soapVersion = isset($options['soap_version'])
            ? (int) $options['soap_version']
            : SoapConstants::SOAP_1_1;
        $state->style = isset($options['style']) ? (int) $options['style'] : SoapConstants::SOAP_RPC;
        $state->use = isset($options['use']) ? (int) $options['use'] : SoapConstants::SOAP_ENCODED;

        if (null !== $wsdl && '' !== $wsdl) {
            self::loadWsdl($state, $wsdl);
        }
        if ('' === $state->location && isset($options['location'])) {
            $state->location = (string) $options['location'];
        }
        if ('' !== ($options['location'] ?? '')) {
            // Explicit location option wins over WSDL soap:address (php-src SoapClient ctor).
            $state->location = (string) $options['location'];
        }

        self::$store[$object->id] = $state;
        $object->constructed = true;
    }

    public static function state(ObjectEntry $object): SoapClientState
    {
        if (!isset(self::$store[$object->id])) {
            throw new \SoapFault('Client', 'SoapClient object has not been correctly initialized by its constructor');
        }

        return self::$store[$object->id];
    }

    public static function isSoapClient(ObjectEntry $object): bool
    {
        $lc = \strtolower($object->class->name);

        return self::CLASS_LC === $lc || isset(self::$store[$object->id]);
    }

    /**
     * @return list<string>
     */
    public static function getFunctions(ObjectEntry $object): array
    {
        return self::state($object)->functions;
    }

    /**
     * @return list<string>
     */
    public static function getTypes(ObjectEntry $object): array
    {
        return self::state($object)->types;
    }

    /**
     * @return array<string, string>
     */
    public static function getCookies(ObjectEntry $object): array
    {
        return self::state($object)->cookies;
    }

    public static function setCookie(ObjectEntry $object, string $name, ?string $value): void
    {
        $state = self::state($object);
        if (null === $value) {
            unset($state->cookies[$name]);

            return;
        }
        $state->cookies[$name] = $value;
    }

    public static function soapCall(
        ObjectEntry $object,
        string $name,
        array $arguments,
        Context $ctx,
        Frame $frame
    ): Variable {
        $state = self::state($object);
        $request = self::buildRequest($state, $name, $arguments);
        $state->lastRequest = $request;

        $action = $state->uri !== '' ? \rtrim($state->uri, '/').'/'.$name : $name;
        $response = self::doRequest($object, $request, $state->location, $action, $state->soapVersion, $frame);
        $state->lastResponse = $response;

        $decoded = self::decodeResponse($response, $name);

        return self::importValue($decoded, $ctx);
    }

    public static function doRequest(
        ObjectEntry $object,
        string $request,
        string $location,
        string $action,
        int $version,
        ?Frame $frame = null
    ): string {
        $state = self::state($object);
        $state->lastRequest = $request;

        $cookieHeader = self::formatCookieHeader($state->cookies);
        $requestHeaders = self::buildHttpRequestHeaders($location, $action, \strlen($request), $cookieHeader);
        if ($state->trace) {
            $state->lastRequestHeaders = $requestHeaders;
        }

        $path = self::localPathFromLocation($location);
        if (null !== $path) {
            $body = @\file_get_contents($path);
            if (false === $body) {
                throw new \SoapFault('HTTP', 'Could not read SOAP response fixture: '.$path);
            }
            $state->lastResponse = $body;
            if ($state->trace) {
                $state->lastResponseHeaders = self::synthesizeFixtureResponseHeaders(\strlen($body));
            }

            return $body;
        }

        if ('' === $location) {
            throw new \SoapFault('Client', 'SoapClient location is not set');
        }

        $headers = "Content-Type: text/xml; charset=utf-8\r\n".
            'SOAPAction: "'.$action."\"\r\n";
        if ('' !== $cookieHeader) {
            $headers .= 'Cookie: '.$cookieHeader."\r\n";
        }
        $ctx = \stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headers,
                'content' => $request,
                'ignore_errors' => true,
                'timeout' => 30,
            ],
        ]);
        $body = @\file_get_contents($location, false, $ctx);
        if (false === $body) {
            throw new \SoapFault('HTTP', 'Could not connect to host');
        }
        $state->lastResponse = $body;
        if ($state->trace) {
            // file_get_contents populates $http_response_header in local scope (php-src HTTP wrapper).
            if (isset($http_response_header) && \is_array($http_response_header) && $http_response_header !== []) {
                $state->lastResponseHeaders = \implode("\r\n", $http_response_header)."\r\n";
            } else {
                $state->lastResponseHeaders = self::synthesizeFixtureResponseHeaders(\strlen($body));
            }
        }

        return $body;
    }

    /**
     * @param array<string, string> $cookies
     */
    private static function formatCookieHeader(array $cookies): string
    {
        if ($cookies === []) {
            return '';
        }
        $parts = [];
        foreach ($cookies as $name => $value) {
            $parts[] = $name.'='.$value;
        }

        return \implode('; ', $parts);
    }

    /**
     * Build Zend-shaped HTTP request header block for trace (php-src soap_client).
     */
    private static function buildHttpRequestHeaders(
        string $location,
        string $action,
        int $contentLength,
        string $cookieHeader = ''
    ): string {
        $path = '/';
        $host = 'localhost';
        if (\preg_match('#^https?://([^/]+)(/.*)?$#i', $location, $m)) {
            $host = $m[1];
            $path = isset($m[2]) && '' !== $m[2] ? $m[2] : '/';
        } elseif ('' !== $location) {
            $path = $location;
        }

        $hdr = 'POST '.$path." HTTP/1.1\r\n".
            'Host: '.$host."\r\n".
            "Connection: Keep-Alive\r\n".
            'User-Agent: PHP-SOAP/'.\PHP_VERSION."\r\n".
            "Content-Type: text/xml; charset=utf-8\r\n".
            'SOAPAction: "'.$action."\"\r\n".
            'Content-Length: '.$contentLength."\r\n";
        if ('' !== $cookieHeader) {
            $hdr .= 'Cookie: '.$cookieHeader."\r\n";
        }

        return $hdr;
    }

    private static function synthesizeFixtureResponseHeaders(int $contentLength): string
    {
        return "HTTP/1.1 200 OK\r\n".
            "Content-Type: text/xml; charset=utf-8\r\n".
            'Content-Length: '.$contentLength."\r\n";
    }

    private static function localPathFromLocation(string $location): ?string
    {
        if ('' === $location) {
            return null;
        }
        if (\str_starts_with($location, 'file://')) {
            $path = \substr($location, 7);
            if (\str_starts_with($path, '/') && \PHP_OS_FAMILY === 'Windows' && \preg_match('#^/[A-Za-z]:#', $path)) {
                $path = \substr($path, 1);
            }

            return \is_file($path) ? $path : null;
        }
        if (\preg_match('#^https?://#i', $location)) {
            return null;
        }
        if (\is_file($location)) {
            return $location;
        }

        return null;
    }

    private static function loadWsdl(SoapClientState $state, string $wsdl): void
    {
        $xml = @\file_get_contents($wsdl);
        if (false === $xml) {
            throw new \SoapFault('WSDL', 'SOAP-ERROR: Parsing WSDL: Couldn\'t load from \''.$wsdl.'\'');
        }
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xml)) {
            throw new \SoapFault('WSDL', 'SOAP-ERROR: Parsing WSDL: Couldn\'t load from \''.$wsdl.'\'');
        }
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('wsdl', 'http://schemas.xmlsoap.org/wsdl/');
        $xpath->registerNamespace('soap', 'http://schemas.xmlsoap.org/wsdl/soap/');
        $xpath->registerNamespace('xsd', 'http://www.w3.org/2001/XMLSchema');

        foreach ($xpath->query('//wsdl:portType/wsdl:operation') ?: [] as $op) {
            if (!$op instanceof \DOMElement) {
                continue;
            }
            $name = $op->getAttribute('name');
            if ('' !== $name) {
                $state->functions[] = $name;
                $state->functionIndex[\strtolower($name)] = $name;
            }
        }
        foreach ($xpath->query('//soap:address') ?: [] as $addr) {
            if (!$addr instanceof \DOMElement) {
                continue;
            }
            $loc = $addr->getAttribute('location');
            if ('' !== $loc && '' === $state->location) {
                $state->location = $loc;
            }
        }
        foreach ($xpath->query('//wsdl:definitions') ?: [] as $defs) {
            if ($defs instanceof \DOMElement) {
                $tns = $defs->getAttribute('targetNamespace');
                if ('' !== $tns && '' === $state->uri) {
                    $state->uri = $tns;
                }
            }
        }
        foreach ($xpath->query('//xsd:complexType|//xsd:simpleType') ?: [] as $type) {
            if ($type instanceof \DOMElement) {
                $n = $type->getAttribute('name');
                if ('' !== $n) {
                    $state->types[] = $n;
                }
            }
        }
    }

    /**
     * @param list<mixed> $arguments
     */
    private static function buildRequest(SoapClientState $state, string $name, array $arguments): string
    {
        $ns = $state->uri !== '' ? $state->uri : 'http://example.com/';
        $envelopeNs = SoapConstants::SOAP_1_2 === $state->soapVersion
            ? 'http://www.w3.org/2003/05/soap-envelope'
            : 'http://schemas.xmlsoap.org/soap/envelope/';
        $prefix = SoapConstants::SOAP_1_2 === $state->soapVersion ? 'env' : 'SOAP-ENV';

        $paramsXml = '';
        $args = $arguments;
        // Zend wraps document/literal params; RPC often uses a single array of named params.
        if (1 === \count($args) && \is_array($args[0]) && !\array_is_list($args[0])) {
            $args = $args[0];
        }
        if (\is_array($args) && !\array_is_list($args)) {
            foreach ($args as $key => $value) {
                $paramsXml .= self::encodeParam((string) $key, $value);
            }
        } else {
            $i = 0;
            foreach ($args as $value) {
                $paramsXml .= self::encodeParam('param'.$i, $value);
                ++$i;
            }
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".
            '<'.$prefix.':Envelope xmlns:'.$prefix.'="'.$envelopeNs.'"'.
            ' xmlns:ns1="'.\htmlspecialchars($ns, \ENT_XML1).'"'.
            ' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'.
            ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'.
            ' xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/"'.
            ' SOAP-ENV:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'."\n".
            '  <'.$prefix.':Body>'."\n".
            '    <ns1:'.$name.'>'.$paramsXml.'</ns1:'.$name.'>'."\n".
            '  </'.$prefix.':Body>'."\n".
            '</'.$prefix.':Envelope>';
    }

    private static function encodeParam(string $name, mixed $value): string
    {
        $tag = \preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) ?: 'param';
        if (null === $value) {
            return '<'.$tag.' xsi:nil="true"/>';
        }
        if (\is_bool($value)) {
            return '<'.$tag.' xsi:type="xsd:boolean">'.($value ? 'true' : 'false').'</'.$tag.'>';
        }
        if (\is_int($value)) {
            return '<'.$tag.' xsi:type="xsd:int">'.$value.'</'.$tag.'>';
        }
        if (\is_float($value)) {
            return '<'.$tag.' xsi:type="xsd:float">'.$value.'</'.$tag.'>';
        }
        if (\is_array($value)) {
            $inner = '';
            foreach ($value as $k => $v) {
                $inner .= self::encodeParam(\is_int($k) ? 'item' : (string) $k, $v);
            }

            return '<'.$tag.'>'.$inner.'</'.$tag.'>';
        }

        return '<'.$tag.' xsi:type="xsd:string">'.\htmlspecialchars((string) $value, \ENT_XML1).'</'.$tag.'>';
    }

    private static function decodeResponse(string $response, string $name): mixed
    {
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($response)) {
            throw new \SoapFault('Client', 'looks like we got no XML document');
        }
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('SOAP-ENV', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xpath->registerNamespace('env', 'http://www.w3.org/2003/05/soap-envelope');

        $fault = $xpath->query('//SOAP-ENV:Fault|//env:Fault');
        if ($fault && $fault->length > 0) {
            $faultEl = $fault->item(0);
            $code = 'Client';
            $string = 'SOAP Fault';
            if ($faultEl instanceof \DOMElement) {
                foreach ($faultEl->childNodes as $child) {
                    if (!$child instanceof \DOMElement) {
                        continue;
                    }
                    $ln = $child->localName ?? $child->nodeName;
                    if ('faultcode' === $ln || 'Code' === $ln) {
                        $code = \trim($child->textContent);
                    }
                    if ('faultstring' === $ln || 'Reason' === $ln) {
                        $string = \trim($child->textContent);
                    }
                }
            }
            throw new \SoapFault($code, $string);
        }

        $body = $xpath->query('//SOAP-ENV:Body/*|//env:Body/*');
        if (!$body || 0 === $body->length) {
            return null;
        }
        $responseEl = $body->item(0);
        if (!$responseEl instanceof \DOMElement) {
            return null;
        }

        $children = [];
        foreach ($responseEl->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $children[$child->localName ?? $child->nodeName] = self::domElementToValue($child);
            }
        }
        if (0 === \count($children)) {
            return \trim($responseEl->textContent);
        }
        if (1 === \count($children)) {
            return \reset($children);
        }

        return (object) $children;
    }

    private static function domElementToValue(\DOMElement $el): mixed
    {
        $childElements = [];
        foreach ($el->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $childElements[] = $child;
            }
        }
        if (0 === \count($childElements)) {
            $text = \trim($el->textContent);
            if ('' === $text && $el->hasAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'nil')) {
                return null;
            }

            return $text;
        }
        $map = [];
        $list = true;
        foreach ($childElements as $child) {
            $key = $child->localName ?? $child->nodeName;
            if (isset($map[$key])) {
                if (!\is_array($map[$key]) || !\array_is_list($map[$key])) {
                    $map[$key] = [$map[$key]];
                }
                $map[$key][] = self::domElementToValue($child);
            } else {
                $map[$key] = self::domElementToValue($child);
            }
            if ('item' !== $key) {
                $list = false;
            }
        }
        if ($list) {
            return \array_values($map);
        }

        return (object) $map;
    }

    private static function importValue(mixed $value, Context $ctx): Variable
    {
        // Reuse json import path via encode/decode of scalars/objects/arrays.
        if ($value instanceof \stdClass || \is_array($value) || null === $value
            || \is_scalar($value)) {
            $json = \json_encode($value, \JSON_THROW_ON_ERROR);
            $decoded = \json_decode($json, false, 512, \JSON_THROW_ON_ERROR);

            return self::importJsonLike($decoded, $ctx);
        }
        $var = new Variable();
        $var->string((string) $value);

        return $var;
    }

    private static function importJsonLike(mixed $value, Context $ctx): Variable
    {
        $var = new Variable();
        if (null === $value) {
            $var->null();

            return $var;
        }
        if (\is_bool($value)) {
            $var->bool($value);

            return $var;
        }
        if (\is_int($value)) {
            $var->int($value);

            return $var;
        }
        if (\is_float($value)) {
            $var->float($value);

            return $var;
        }
        if (\is_string($value)) {
            $var->string($value);

            return $var;
        }
        if (\is_array($value)) {
            $ht = new HashTable();
            $isList = \array_is_list($value);
            foreach ($value as $key => $item) {
                $slot = self::importJsonLike($item, $ctx);
                if ($isList) {
                    $ht->addIndex((int) $key, $slot);
                } else {
                    $ht->add((string) $key, $slot);
                }
            }
            $var->array($ht);

            return $var;
        }
        if ($value instanceof \stdClass) {
            if (!isset($ctx->classes['stdclass'])) {
                throw new \LogicException('stdClass is not registered');
            }
            $object = new ObjectEntry($ctx->classes['stdclass']);
            $object->constructed = true;
            foreach ((array) $value as $key => $item) {
                $object->allocateProperty((string) $key)
                    ->copyFrom(self::importJsonLike($item, $ctx));
            }
            $var->object($object);

            return $var;
        }
        $var->null();

        return $var;
    }

    /**
     * @return list<mixed>
     */
    public static function exportArguments(Variable $argsVar, ?Frame $frame): array
    {
        $argsVar = $argsVar->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $argsVar->type) {
            throw new \TypeError('SoapClient::__soapCall(): Argument #2 ($arguments) must be of type array');
        }

        return VmJson::export($argsVar, $frame?->vmContext ?? null, null, $frame);
    }
}

final class SoapClientState
{
    public ?string $wsdl = null;

    /** @var array<string, mixed> */
    public array $options = [];

    public string $location = '';

    public string $uri = '';

    public bool $trace = false;

    public int $soapVersion = SoapConstants::SOAP_1_1;

    public int $style = SoapConstants::SOAP_RPC;

    public int $use = SoapConstants::SOAP_ENCODED;

    /** @var list<string> */
    public array $functions = [];

    /** @var array<string, string> */
    public array $functionIndex = [];

    /** @var list<string> */
    public array $types = [];

    public string $lastRequest = '';

    public string $lastResponse = '';

    public ?string $lastRequestHeaders = null;

    public ?string $lastResponseHeaders = null;

    /** @var array<string, string> */
    public array $cookies = [];
}

final class SoapClientConstruct extends SoapClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        // calledArgs[0] = $this
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'SoapClient::__construct() expects at least 1 argument and at most 2, '.($argc - 1).' given'
            );
        }
        $receiver = $this->receiver($frame, 'SoapClient::__construct()');
        $wsdlArg = $frame->calledArgs[1]->resolveIndirect();
        $wsdl = null;
        if (Variable::TYPE_NULL !== $wsdlArg->type) {
            $wsdl = $this->stringArg($frame->calledArgs[1], 'SoapClient::__construct', 0, 'wsdl');
        }
        $options = [];
        if ($argc >= 3) {
            $optVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $optVar->type) {
                throw new \TypeError(
                    'SoapClient::__construct(): Argument #2 ($options) must be of type array'
                );
            }
            $exported = VmJson::export($optVar, $frame->vmContext, null, $frame);
            if (\is_array($exported)) {
                $options = $exported;
            }
        }
        VmSoapClient::initObject($receiver, $wsdl, $options, $frame->vmContext);
    }
}

final class SoapClientSoapCall extends SoapClassMethod
{
    public function __construct()
    {
        parent::__construct('__soapCall');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(
                'SoapClient::__soapCall() expects at least 2 arguments, '.($argc - 1).' given'
            );
        }
        $receiver = $this->receiver($frame, 'SoapClient::__soapCall()');
        $name = $this->stringArg($frame->calledArgs[1], 'SoapClient::__soapCall', 0, 'name');
        $arguments = VmSoapClient::exportArguments($frame->calledArgs[2], $frame);
        if (!\is_array($arguments)) {
            $arguments = [];
        }
        $result = VmSoapClient::soapCall($receiver, $name, $arguments, $frame->vmContext, $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }
}

final class SoapClientCall extends SoapClassMethod
{
    public function __construct()
    {
        parent::__construct('__call');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(
                'SoapClient::__call() expects exactly 2 arguments, '.($argc - 1).' given'
            );
        }
        $receiver = $this->receiver($frame, 'SoapClient::__call()');
        $name = $this->stringArg($frame->calledArgs[1], 'SoapClient::__call', 0, 'name');
        $arguments = VmSoapClient::exportArguments($frame->calledArgs[2], $frame);
        if (!\is_array($arguments)) {
            $arguments = [];
        }
        $result = VmSoapClient::soapCall($receiver, $name, $arguments, $frame->vmContext, $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }
}

final class SoapClientDoRequest extends SoapClassMethod
{
    public function __construct()
    {
        parent::__construct('__doRequest');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 5 || $argc > 6) {
            throw new \ArgumentCountError(
                'SoapClient::__doRequest() expects at least 4 arguments and at most 5, '.($argc - 1).' given'
            );
        }
        $receiver = $this->receiver($frame, 'SoapClient::__doRequest()');
        $request = $this->stringArg($frame->calledArgs[1], 'SoapClient::__doRequest', 0, 'request');
        $location = $this->stringArg($frame->calledArgs[2], 'SoapClient::__doRequest', 1, 'location');
        $action = $this->stringArg($frame->calledArgs[3], 'SoapClient::__doRequest', 2, 'action');
        $version = (int) $frame->calledArgs[4]->resolveIndirect()->toInt();
        $response = VmSoapClient::doRequest($receiver, $request, $location, $action, $version, $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->string($response);
        }
    }
}

final class SoapClientGetFunctions extends SoapClassMethod
{
    public function __construct()
    {
        parent::__construct('__getFunctions');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SoapClient::__getFunctions()');
        if (null === $frame->returnVar) {
            return;
        }
        $ht = new HashTable();
        $i = 0;
        foreach (VmSoapClient::getFunctions($receiver) as $fn) {
            $slot = new Variable();
            $slot->string($fn);
            $ht->addIndex($i, $slot);
            ++$i;
        }
        $frame->returnVar->array($ht);
    }
}

final class SoapClientGetTypes extends SoapClassMethod
{
    public function __construct()
    {
        parent::__construct('__getTypes');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SoapClient::__getTypes()');
        if (null === $frame->returnVar) {
            return;
        }
        $ht = new HashTable();
        $i = 0;
        foreach (VmSoapClient::getTypes($receiver) as $type) {
            $slot = new Variable();
            $slot->string($type);
            $ht->addIndex($i, $slot);
            ++$i;
        }
        $frame->returnVar->array($ht);
    }
}

final class SoapClientGetLastRequest extends SoapClassMethod
{
    public function __construct()
    {
        parent::__construct('__getLastRequest');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SoapClient::__getLastRequest()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmSoapClient::state($receiver)->lastRequest);
    }
}

final class SoapClientGetLastResponse extends SoapClassMethod
{
    public function __construct()
    {
        parent::__construct('__getLastResponse');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SoapClient::__getLastResponse()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmSoapClient::state($receiver)->lastResponse);
    }
}

final class SoapClientGetLastRequestHeaders extends SoapClassMethod
{
    public function __construct()
    {
        parent::__construct('__getLastRequestHeaders');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SoapClient::__getLastRequestHeaders()');
        if (null === $frame->returnVar) {
            return;
        }
        $headers = VmSoapClient::state($receiver)->lastRequestHeaders;
        if (null === $headers || '' === $headers) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->string($headers);
    }
}

final class SoapClientGetLastResponseHeaders extends SoapClassMethod
{
    public function __construct()
    {
        parent::__construct('__getLastResponseHeaders');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SoapClient::__getLastResponseHeaders()');
        if (null === $frame->returnVar) {
            return;
        }
        $headers = VmSoapClient::state($receiver)->lastResponseHeaders;
        if (null === $headers || '' === $headers) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->string($headers);
    }
}

final class SoapClientSetCookie extends SoapClassMethod
{
    public function __construct()
    {
        parent::__construct('__setCookie');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'SoapClient::__setCookie() expects at least 1 argument and at most 2, '.($argc - 1).' given'
            );
        }
        $receiver = $this->receiver($frame, 'SoapClient::__setCookie()');
        $name = $this->stringArg($frame->calledArgs[1], 'SoapClient::__setCookie', 0, 'name');
        $value = null;
        if ($argc >= 3) {
            $valVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $valVar->type) {
                $value = $this->stringArg($frame->calledArgs[2], 'SoapClient::__setCookie', 1, 'value');
            }
        }
        VmSoapClient::setCookie($receiver, $name, $value);
    }
}

final class SoapClientGetCookies extends SoapClassMethod
{
    public function __construct()
    {
        parent::__construct('__getCookies');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SoapClient::__getCookies()');
        if (null === $frame->returnVar) {
            return;
        }
        $ht = new HashTable();
        foreach (VmSoapClient::getCookies($receiver) as $name => $value) {
            $slot = new Variable();
            $slot->string($value);
            $ht->add((string) $name, $slot);
        }
        $frame->returnVar->array($ht);
    }
}
