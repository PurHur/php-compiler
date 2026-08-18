<?php

declare(strict_types=1);

namespace PHPCompiler\ext\soap;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Compiler\ParameterMetadata;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\ReflectionTypeSupport;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\BuiltinExceptionSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\ext\standard\VmHttpBuildQuery;
use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmStreamContext;
use PHPCompiler\ext\standard\VmUserCall;

/**
 * SoapClient VM class — v1 local WSDL + file/HTTP transport (php-src ext/soap/soap.c; #20037, #20183, #20293).
 *
 * Fixture mode: options['location'] may be a local filesystem path or file:// URL; __doRequest
 * returns that file's contents (no network). HTTP locations use host file_get_contents POST.
 * With options['trace'], __getLastRequestHeaders / __getLastResponseHeaders capture HTTP header blocks.
 * options['exceptions']=false returns SoapFault objects instead of throwing (#20293).
 * options['classmap'] maps SOAP type names (xsi:type local name) to PHP classes (#21044).
 * WSDL xsd:element[@type] bindings also drive classmap when xsi:type is absent (#21088).
 * Document/literal operation→output message parts + complexType fields scope nested SDL types (#21091).
 * Document/literal operation→input sequence names positional __soapCall args (#21131).
 * WSDL soap:binding style / soap:body use applied when ctor omits style/use (#21132).
 * options['typemap'] from_xml/to_xml string or Closure/callable callbacks (#21046 / #31845).
 * __soapCall $options location/soapaction/uri apply per-call without mutating ctor state (#31873).
 * SOAP 1.2 HTTP uses application/soap+xml; action= (no SOAPAction header) (#31918 / php_http.c).
 * SOAP 1.2 encoded requests use env:encodingStyle + SOAP_1_2_ENC_NAMESPACE (#31919).
 * SOAP 1.2 SoapHeader uses role + mustUnderstand=true (#31920 / soap.c).
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
        // php-src stub marks private; expose readable like httpurl/sdl (#23246/#23922).
        $have = [];
        foreach ($entry->properties as $prop) {
            $have[$prop->name] = true;
        }
        $nullProto = new Variable(Variable::TYPE_NULL);
        $strProto = new Variable(Variable::TYPE_STRING);
        $intProto = new Variable(Variable::TYPE_INTEGER);
        $boolProto = new Variable(Variable::TYPE_BOOLEAN);
        $falseDefault = new Variable(Variable::TYPE_BOOLEAN);
        $falseDefault->bool(false);
        // Core ctor option props (soap.stub.php; #23922).
        $coreProps = [
            'uri' => [$nullProto, $strProto],
            'style' => [$nullProto, $intProto],
            'use' => [$nullProto, $intProto],
            'location' => [$nullProto, $strProto],
            'trace' => [$falseDefault, $boolProto],
            'compression' => [$nullProto, $intProto],
        ];
        foreach ($coreProps as $propName => [$default, $proto]) {
            if (!isset($have[$propName])) {
                $entry->properties[] = new ClassProperty(
                    $propName,
                    $default,
                    $proto,
                    false,
                    $pub,
                    self::CLASS_LC
                );
                $have[$propName] = true;
            }
        }
        // Underscored option props (soap.stub.php; #23923).
        $trueDefault = new Variable(Variable::TYPE_BOOLEAN);
        $trueDefault->bool(true);
        $zeroDefault = new Variable(Variable::TYPE_INTEGER);
        $zeroDefault->int(0);
        $soap11Default = new Variable(Variable::TYPE_INTEGER);
        $soap11Default->int(SoapConstants::SOAP_1_1);
        $arrayProto = new Variable(Variable::TYPE_ARRAY);
        $uscoreProps = [
            '_login' => [$nullProto, $strProto],
            '_password' => [$nullProto, $strProto],
            '_encoding' => [$nullProto, $strProto],
            '_classmap' => [$nullProto, $arrayProto],
            '_features' => [$nullProto, $intProto],
            '_connection_timeout' => [$zeroDefault, $intProto],
            '_keep_alive' => [$trueDefault, $boolProto],
            '_ssl_method' => [$nullProto, $intProto],
            '_soap_version' => [$soap11Default, $intProto],
            '_exceptions' => [$trueDefault, $boolProto],
            '_user_agent' => [$nullProto, $strProto],
        ];
        foreach ($uscoreProps as $propName => [$default, $proto]) {
            if (!isset($have[$propName])) {
                $entry->properties[] = new ClassProperty(
                    $propName,
                    $default,
                    $proto,
                    false,
                    $pub,
                    self::CLASS_LC
                );
                $have[$propName] = true;
            }
        }
        // Proxy/digest/stream/cookies props (soap.stub.php; #23924).
        $emptyArrayDefault = new Variable(Variable::TYPE_ARRAY);
        $emptyArrayDefault->array(new HashTable());
        $proxyProps = [
            '_proxy_host' => [$nullProto, $strProto],
            '_proxy_port' => [$nullProto, $intProto],
            '_proxy_login' => [$nullProto, $strProto],
            '_proxy_password' => [$nullProto, $strProto],
            '_use_proxy' => [$nullProto, $intProto],
            '_use_digest' => [$falseDefault, $boolProto],
            '_digest' => [$nullProto, $strProto],
            '_stream_context' => [$nullProto, $nullProto],
            '_cookies' => [$emptyArrayDefault, $arrayProto],
        ];
        foreach ($proxyProps as $propName => [$default, $proto]) {
            if (!isset($have[$propName])) {
                $entry->properties[] = new ClassProperty(
                    $propName,
                    $default,
                    $proto,
                    false,
                    $pub,
                    self::CLASS_LC
                );
                $have[$propName] = true;
            }
        }
        // Trace / fault bag props (soap.stub.php; #23925).
        $traceFaultProps = [
            '__last_request' => [$nullProto, $strProto],
            '__last_response' => [$nullProto, $strProto],
            '__last_request_headers' => [$nullProto, $strProto],
            '__last_response_headers' => [$nullProto, $strProto],
            '__default_headers' => [$nullProto, $arrayProto],
            '__soap_fault' => [$nullProto, $nullProto],
        ];
        foreach ($traceFaultProps as $propName => [$default, $proto]) {
            if (!isset($have[$propName])) {
                $entry->properties[] = new ClassProperty(
                    $propName,
                    $default,
                    $proto,
                    false,
                    $pub,
                    self::CLASS_LC
                );
                $have[$propName] = true;
            }
        }
        // php-src stub marks private; UPGRADING / soap.stub.php userland reads (#23246/#23247/#23903/#23904).
        if (SoapExtensionPolicy::advertisesOpaqueUrlSdlTypes()) {
            foreach (['httpurl', 'sdl', 'typemap', 'httpsocket'] as $propName) {
                if (!isset($have[$propName])) {
                    $entry->properties[] = new ClassProperty(
                        $propName,
                        null,
                        $nullProto,
                        false,
                        $pub,
                        self::CLASS_LC
                    );
                }
            }
        }

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
            '__setlocation' => new SoapClientSetLocation(),
            '__setsoapheaders' => new SoapClientSetSoapHeaders(),
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
        $entry->methodNames['__setlocation'] = '__setLocation';
        $entry->methodNames['__setsoapheaders'] = '__setSoapHeaders';

        // php-src soap.stub.php — LSP/Reflection for __doRequest (#31568).
        // InternalArgInfo historically omitted $location and used one_way:int.
        $entry->methodParameterMetadata['__dorequest'] = [
            new ParameterMetadata('request', [], false, false, false, false, 'string', null),
            new ParameterMetadata('location', [], false, false, false, false, 'string', null),
            new ParameterMetadata('action', [], false, false, false, false, 'string', null),
            new ParameterMetadata('version', [], false, false, false, false, 'int', null),
            new ParameterMetadata('oneWay', [], false, true, false, false, 'bool', 'false'),
        ];
        // php-src soap.stub.php — __soapCall name/args/options/?array (#31873).
        // inputHeaders emit #31874; outputHeaders by-ref + parse #31875.
        $entry->methodParameterMetadata['__soapcall'] = [
            new ParameterMetadata('name', [], false, false, false, false, 'string', null),
            new ParameterMetadata('args', [], false, false, false, false, 'array', null),
            new ParameterMetadata('options', [], false, true, false, false, '?array', 'null'),
            new ParameterMetadata('inputHeaders', [], false, true, false, false, null, 'null'),
            new ParameterMetadata('outputHeaders', [], false, true, false, true, null, 'null'),
        ];
        $doRequestReturn = ReflectionTypeSupport::cfgTypeFromLabel('?string');
        if (null !== $doRequestReturn) {
            $entry->methodReturnDeclaredTypes['__dorequest'] = $doRequestReturn;
        }

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function initObject(ObjectEntry $object, ?string $wsdl, array $options, Context $ctx): void
    {
        $state = new SoapClientState();
        $state->vmContext = $ctx;
        $state->wsdl = $wsdl;
        $state->options = $options;
        $state->location = isset($options['location']) ? (string) $options['location'] : '';
        $state->uri = isset($options['uri']) ? (string) $options['uri'] : '';
        $state->trace = !empty($options['trace']);
        $state->soapVersion = isset($options['soap_version'])
            ? (int) $options['soap_version']
            : SoapConstants::SOAP_1_1;
        $state->styleFromOptions = \array_key_exists('style', $options);
        $state->useFromOptions = \array_key_exists('use', $options);
        $state->style = isset($options['style']) ? (int) $options['style'] : SoapConstants::SOAP_RPC;
        $state->use = isset($options['use']) ? (int) $options['use'] : SoapConstants::SOAP_ENCODED;
        // php-src: exceptions false when IS_FALSE or long 0 (#20293).
        if (\array_key_exists('exceptions', $options)) {
            $ex = $options['exceptions'];
            $state->exceptions = !(false === $ex || 0 === $ex || '0' === $ex);
        }

        // php-src SoapClient ctor: login/password → HTTP Authorization (#20312).
        if (isset($options['login'])) {
            $state->login = (string) $options['login'];
        }
        if (isset($options['password'])) {
            $state->password = (string) $options['password'];
        }
        if (isset($options['authentication'])) {
            $state->authentication = (int) $options['authentication'];
        }
        // php-src Z_CLIENT_COMPRESSION (#20313).
        if (isset($options['compression'])) {
            $state->compression = (int) $options['compression'];
        }
        // php-src SoapClient ctor: proxy_host/port/login/password (#20339).
        if (isset($options['proxy_host'])) {
            $state->proxyHost = (string) $options['proxy_host'];
        }
        if (isset($options['proxy_port'])) {
            $state->proxyPort = (int) $options['proxy_port'];
        }
        if (isset($options['proxy_login'])) {
            $state->proxyLogin = (string) $options['proxy_login'];
        }
        if (isset($options['proxy_password'])) {
            $state->proxyPassword = (string) $options['proxy_password'];
        }
        // php-src SoapClient ctor: user_agent / connection_timeout (#20341).
        if (isset($options['user_agent']) && \is_string($options['user_agent'])) {
            $state->userAgent = $options['user_agent'];
        }
        if (isset($options['connection_timeout'])) {
            $timeout = (int) $options['connection_timeout'];
            if ($timeout > 0) {
                $state->connectionTimeout = $timeout;
            }
        }
        // php-src SoapClient ctor: keep_alive false / long 0 → Connection: close (#20364).
        if (\array_key_exists('keep_alive', $options)) {
            $ka = $options['keep_alive'];
            $state->keepAlive = !(false === $ka || 0 === $ka || '0' === $ka);
        }
        // php-src SoapClient ctor: stream_context resource → HTTP/SSL options (#20365).
        if (isset($options['__phpc_stream_context_options']) && \is_array($options['__phpc_stream_context_options'])) {
            $state->streamContextOptions = $options['__phpc_stream_context_options'];
            unset($options['__phpc_stream_context_options']);
            $state->options = $options;
        }
        // php-src SoapClient ctor: ssl_method long (#20366).
        if (isset($options['ssl_method']) && (\is_int($options['ssl_method']) || \is_float($options['ssl_method']))) {
            $state->sslMethod = (int) $options['ssl_method'];
        }
        // php-src SoapClient ctor: features bitmask (#20367).
        if (isset($options['features']) && (\is_int($options['features']) || \is_float($options['features']))) {
            $state->features = (int) $options['features'];
            $state->featuresFromOptions = true;
        }
        // php-src SoapClient ctor: encoding (#23923 / Z_CLIENT_ENCODING).
        if (isset($options['encoding']) && \is_string($options['encoding']) && '' !== $options['encoding']) {
            $state->encoding = $options['encoding'];
        }
        // php-src SoapClient ctor: classmap type_name → PHP class (#21044; php_encoding.c to_zval_object_ex).
        if (isset($options['classmap']) && \is_array($options['classmap'])) {
            $state->classmap = self::normalizeClassmap($options['classmap']);
        }
        // php-src SoapClient ctor: typemap [{type_ns,type_name,from_xml,to_xml}] (#21046 / #31845).
        if (isset($options['__phpc_typemap']) && \is_array($options['__phpc_typemap'])) {
            $state->typemap = $options['__phpc_typemap'];
            unset($options['__phpc_typemap']);
            $state->options = $options;
        } elseif (isset($options['typemap']) && \is_array($options['typemap'])) {
            $state->typemap = self::normalizeTypemap($options['typemap']);
        }
        // php-src SoapClient ctor: cache_wsdl / soap.wsdl_cache_* (#26511 / php_sdl.c get_sdl).
        $state->cacheWsdl = SoapWsdlCache::resolveCacheMode($options);

        if (null !== $wsdl && '' !== $wsdl) {
            self::loadWsdl($state, $wsdl);
            // php-src soap.c ctor — attach Soap\Sdl after successful WSDL parse (#23247 / #23905).
            self::attachSdl($object, $ctx, $state);
        }
        // php-src soap.c — Z_CLIENT_TYPEMAP gets array after soap_create_typemap (#23903 / UPGRADING 8.4).
        self::attachTypemap($object, $ctx, $state->typemap);
        if ('' === $state->location && isset($options['location'])) {
            $state->location = (string) $options['location'];
        }
        if ('' !== ($options['location'] ?? '')) {
            // Explicit location option wins over WSDL soap:address (php-src SoapClient ctor).
            $state->location = (string) $options['location'];
        }

        self::$store[$object->id] = $state;
        // php-src soap.c ctor — Z_CLIENT_URI/STYLE/USE/LOCATION/TRACE/COMPRESSION (#23922).
        self::syncCoreOptionProperties($object, $state);
        // php-src soap.c ctor — underscored option props (#23923).
        self::syncUnderscoredOptionProperties($object, $state, $ctx);
        // php-src soap.c ctor — proxy/digest/stream/cookies (#23924).
        self::syncProxyCookieProperties($object, $state, $ctx);
        $object->constructed = true;
    }

    /**
     * php-src soap_client_call_impl — per-call location always; soapaction/uri only without SDL (#31873).
     *
     * @param array<string, mixed>|null $callOptions
     * @return array{0: string, 1: string, 2: ?string}
     */
    private static function resolveCallOptions(SoapClientState $state, string $name, ?array $callOptions): array
    {
        $location = $state->location;
        $callUri = null;
        $soapActionOpt = null;
        $hasWsdl = null !== $state->wsdl && '' !== $state->wsdl;
        if (null !== $callOptions) {
            if (isset($callOptions['location']) && \is_string($callOptions['location'])) {
                $location = $callOptions['location'];
            }
            if (!$hasWsdl) {
                if (isset($callOptions['soapaction']) && \is_string($callOptions['soapaction'])) {
                    $soapActionOpt = $callOptions['soapaction'];
                }
                if (isset($callOptions['uri']) && \is_string($callOptions['uri'])) {
                    $callUri = $callOptions['uri'];
                }
            }
        }
        if (null !== $soapActionOpt) {
            $action = $soapActionOpt;
        } else {
            $uriForAction = $callUri ?? $state->uri;
            $action = '' !== $uriForAction ? \rtrim($uriForAction, '/').'/'.$name : $name;
        }

        return [$location, $action, $callUri];
    }

    /**
     * php-src soap_client_call_impl $inputHeaders — SoapHeader|array|null (#31874).
     *
     * @return list<ObjectEntry>
     */
    public static function parseInputHeadersArg(Variable $arg, string $label): array
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_NULL === $arg->type) {
            return [];
        }
        if (Variable::TYPE_OBJECT === $arg->type) {
            $obj = $arg->toObject();
            if ('soapheader' !== \strtolower($obj->class->name)) {
                throw new \TypeError(
                    $label.': Argument #4 ($inputHeaders) must be of type SoapHeader|array|null, '
                    .ReflectionSupport::valueTypeLabelPublic($arg).' given'
                );
            }

            return [$obj];
        }
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \TypeError(
                $label.': Argument #4 ($inputHeaders) must be of type SoapHeader|array|null, '
                .ReflectionSupport::valueTypeLabelPublic($arg).' given'
            );
        }
        $headers = [];
        foreach ($arg->toArray()->iterateKeyed(false) as $pair) {
            $v = $pair[1]->resolveIndirect();
            if (Variable::TYPE_OBJECT !== $v->type || 'soapheader' !== \strtolower($v->toObject()->class->name)) {
                // php-src verify_soap_headers_array — E_ERROR "Invalid SOAP header"
                throw new \Error('Invalid SOAP header');
            }
            $headers[] = $v->toObject();
        }

        return $headers;
    }

    /**
     * php-src php_packet_soap.c — Header children → &$outputHeaders keyed by local name (#31875).
     *
     * @return array<string, mixed>
     */
    private static function extractSoapOutputHeaders(string $response): array
    {
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($response)) {
            return [];
        }
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('SOAP-ENV', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xpath->registerNamespace('env', 'http://www.w3.org/2003/05/soap-envelope');
        $nodes = $xpath->query('//SOAP-ENV:Header/*|//env:Header/*');
        if (!$nodes || 0 === $nodes->length) {
            return [];
        }
        $out = [];
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $name = $node->localName ?? $node->nodeName;
            $out[$name] = self::domElementToValue($node);
        }

        return $out;
    }

    private static function writeOutputHeaders(Variable $outputHeadersVar, string $response, Context $ctx): void
    {
        $ht = new HashTable();
        foreach (self::extractSoapOutputHeaders($response) as $name => $value) {
            $ht->add((string) $name, self::importValue($value, $ctx));
        }
        $outputHeadersVar->byRefTarget()->array($ht);
    }

    /**
     * Mirror SoapClientState onto stub core option properties (#23922 / soap.stub.php).
     */
    private static function syncCoreOptionProperties(ObjectEntry $object, SoapClientState $state): void
    {
        if ($object->hasProperty('uri')) {
            $slot = $object->getProperty('uri');
            if ('' !== $state->uri) {
                $slot->string($state->uri);
            } else {
                $slot->null();
            }
        }
        if ($object->hasProperty('location')) {
            $slot = $object->getProperty('location');
            if ('' !== $state->location) {
                $slot->string($state->location);
            } else {
                $slot->null();
            }
        }
        if ($object->hasProperty('style')) {
            $slot = $object->getProperty('style');
            if ($state->styleFromOptions) {
                $slot->int($state->style);
            } else {
                $slot->null();
            }
        }
        if ($object->hasProperty('use')) {
            $slot = $object->getProperty('use');
            if ($state->useFromOptions) {
                $slot->int($state->use);
            } else {
                $slot->null();
            }
        }
        if ($object->hasProperty('trace')) {
            $object->getProperty('trace')->bool($state->trace);
        }
        if ($object->hasProperty('compression')) {
            $slot = $object->getProperty('compression');
            if (null !== $state->compression) {
                $slot->int($state->compression);
            } else {
                $slot->null();
            }
        }
    }

    /**
     * Mirror SoapClientState onto underscored stub option properties (#23923 / soap.stub.php).
     */
    private static function syncUnderscoredOptionProperties(
        ObjectEntry $object,
        SoapClientState $state,
        Context $ctx
    ): void {
        if ($object->hasProperty('_login')) {
            $slot = $object->getProperty('_login');
            if (null !== $state->login) {
                $slot->string($state->login);
            } else {
                $slot->null();
            }
        }
        if ($object->hasProperty('_password')) {
            $slot = $object->getProperty('_password');
            if (null !== $state->password) {
                $slot->string($state->password);
            } else {
                $slot->null();
            }
        }
        if ($object->hasProperty('_encoding')) {
            $slot = $object->getProperty('_encoding');
            if (null !== $state->encoding) {
                $slot->string($state->encoding);
            } else {
                $slot->null();
            }
        }
        if ($object->hasProperty('_classmap')) {
            $slot = $object->getProperty('_classmap');
            if ([] === $state->classmap) {
                $slot->null();
            } else {
                $slot->copyFrom(self::importDecodedTree($state->classmap, $ctx));
            }
        }
        if ($object->hasProperty('_features')) {
            $slot = $object->getProperty('_features');
            if ($state->featuresFromOptions) {
                $slot->int($state->features);
            } else {
                $slot->null();
            }
        }
        if ($object->hasProperty('_connection_timeout')) {
            $object->getProperty('_connection_timeout')->int(
                null !== $state->connectionTimeout ? $state->connectionTimeout : 0
            );
        }
        if ($object->hasProperty('_keep_alive')) {
            $object->getProperty('_keep_alive')->bool($state->keepAlive);
        }
        if ($object->hasProperty('_ssl_method')) {
            $slot = $object->getProperty('_ssl_method');
            if (null !== $state->sslMethod) {
                $slot->int($state->sslMethod);
            } else {
                $slot->null();
            }
        }
        if ($object->hasProperty('_soap_version')) {
            $object->getProperty('_soap_version')->int($state->soapVersion);
        }
        if ($object->hasProperty('_exceptions')) {
            $object->getProperty('_exceptions')->bool($state->exceptions);
        }
        if ($object->hasProperty('_user_agent')) {
            $slot = $object->getProperty('_user_agent');
            if (null !== $state->userAgent) {
                $slot->string($state->userAgent);
            } else {
                $slot->null();
            }
        }
    }

    /**
     * Mirror proxy/digest/stream/cookies stub properties (#23924 / soap.stub.php).
     */
    private static function syncProxyCookieProperties(
        ObjectEntry $object,
        SoapClientState $state,
        Context $ctx
    ): void {
        if ($object->hasProperty('_proxy_host')) {
            $slot = $object->getProperty('_proxy_host');
            if (null !== $state->proxyHost) {
                $slot->string($state->proxyHost);
            } else {
                $slot->null();
            }
        }
        if ($object->hasProperty('_proxy_port')) {
            $slot = $object->getProperty('_proxy_port');
            if (null !== $state->proxyPort) {
                $slot->int($state->proxyPort);
            } else {
                $slot->null();
            }
        }
        if ($object->hasProperty('_proxy_login')) {
            $slot = $object->getProperty('_proxy_login');
            if (null !== $state->proxyLogin) {
                $slot->string($state->proxyLogin);
            } else {
                $slot->null();
            }
        }
        if ($object->hasProperty('_proxy_password')) {
            $slot = $object->getProperty('_proxy_password');
            if (null !== $state->proxyPassword) {
                $slot->string($state->proxyPassword);
            } else {
                $slot->null();
            }
        }
        // php-src sets _use_proxy during http_connect; null until then (#23924).
        if ($object->hasProperty('_use_proxy')) {
            $object->getProperty('_use_proxy')->null();
        }
        if ($object->hasProperty('_use_digest')) {
            $object->getProperty('_use_digest')->bool(
                SoapConstants::SOAP_AUTHENTICATION_DIGEST === $state->authentication
            );
        }
        // Challenge payload lives in state; stub types as ?string — leave null until stringified attach.
        if ($object->hasProperty('_digest')) {
            $object->getProperty('_digest')->null();
        }
        if ($object->hasProperty('_stream_context')) {
            $object->getProperty('_stream_context')->null();
        }
        if ($object->hasProperty('_cookies')) {
            self::syncCookiesProperty($object, $state, $ctx);
        }
    }

    /** Keep `_cookies` array property aligned with SoapClientState::$cookies (#23924). */
    private static function syncCookiesProperty(
        ObjectEntry $object,
        SoapClientState $state,
        Context $ctx
    ): void {
        if (!$object->hasProperty('_cookies')) {
            return;
        }
        $object->getProperty('_cookies')->copyFrom(self::importDecodedTree($state->cookies, $ctx));
    }

    /**
     * Mirror SoapClientState trace bags onto stub __last_* properties (#23925 / soap.stub.php).
     * Only when options['trace'] is on — matches php-src Z_CLIENT_TRACE writes.
     */
    private static function syncTraceProperties(ObjectEntry $object, SoapClientState $state): void
    {
        if (!$state->trace) {
            return;
        }
        if ($object->hasProperty('__last_request')) {
            $object->getProperty('__last_request')->string($state->lastRequest);
        }
        if ($object->hasProperty('__last_response')) {
            $object->getProperty('__last_response')->string($state->lastResponse);
        }
        if ($object->hasProperty('__last_request_headers')) {
            $slot = $object->getProperty('__last_request_headers');
            $headers = $state->lastRequestHeaders;
            if (null === $headers || '' === $headers) {
                $slot->null();
            } else {
                $slot->string($headers);
            }
        }
        if ($object->hasProperty('__last_response_headers')) {
            $slot = $object->getProperty('__last_response_headers');
            $headers = $state->lastResponseHeaders;
            if (null === $headers || '' === $headers) {
                $slot->null();
            } else {
                $slot->string($headers);
            }
        }
    }

    /** Keep `__default_headers` aligned with SoapClientState::$soapHeaders (#23925). */
    private static function syncDefaultHeadersProperty(ObjectEntry $object, SoapClientState $state): void
    {
        if (!$object->hasProperty('__default_headers')) {
            return;
        }
        $slot = $object->getProperty('__default_headers');
        if ([] === $state->soapHeaders) {
            $slot->null();

            return;
        }
        $ht = new HashTable();
        foreach ($state->soapHeaders as $i => $hdr) {
            $v = new Variable();
            $v->object($hdr);
            $ht->addIndex((int) $i, $v);
        }
        $slot->array($ht);
    }

    /** Keep `__soap_fault` aligned with the last SoapFault (#23925). */
    private static function syncSoapFaultProperty(ObjectEntry $object, Variable $faultVar): void
    {
        if (!$object->hasProperty('__soap_fault')) {
            return;
        }
        $object->getProperty('__soap_fault')->copyFrom($faultVar);
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
     * @return array<string, array<int, mixed>>
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
        } else {
            // php-src soap.c __setCookie — array_init + add_index_str(..., 0, val) (#31569).
            $state->cookies[$name] = [0 => $value];
        }
        // Keep stub `_cookies` in sync (#23924).
        if (null !== $state->vmContext) {
            self::syncCookiesProperty($object, $state, $state->vmContext);
        }
    }

    public static function setLocation(ObjectEntry $object, string $location): string
    {
        $state = self::state($object);
        $previous = $state->location;
        $state->location = $location;
        // Keep stub $location in sync with __setLocation (#23922).
        if ($object->hasProperty('location')) {
            $slot = $object->getProperty('location');
            if ('' !== $location) {
                $slot->string($location);
            } else {
                $slot->null();
            }
        }

        return $previous;
    }

    /**
     * @param list<ObjectEntry> $headers
     */
    public static function setSoapHeaders(ObjectEntry $object, array $headers): void
    {
        $state = self::state($object);
        $state->soapHeaders = $headers;
        // Keep stub `__default_headers` in sync (#23925).
        self::syncDefaultHeadersProperty($object, $state);
    }

    /**
     * @param array<string, mixed>|null $callOptions php-src __soapCall $options (location/soapaction/uri) (#31873)
     * @param list<ObjectEntry>         $inputHeaders per-call SoapHeaders merged ahead of defaults (#31874)
     * @param ?Variable                 $outputHeadersVar by-ref 5th arg; Header children keyed by local name (#31875)
     */
    public static function soapCall(
        ObjectEntry $object,
        string $name,
        array $arguments,
        Context $ctx,
        Frame $frame,
        ?array $callOptions = null,
        array $inputHeaders = [],
        ?Variable $outputHeadersVar = null
    ): Variable {
        $state = self::state($object);
        // php-src soap.c soap_client_call_impl — zend_try_array_init before parse (#31875).
        if (null !== $outputHeadersVar) {
            $outputHeadersVar->byRefTarget()->array(new HashTable());
        }
        try {
            [$location, $action, $callUri] = self::resolveCallOptions($state, $name, $callOptions);
            $request = self::buildRequest($state, $name, $arguments, $ctx, $callUri, $inputHeaders);
            $state->lastRequest = $request;

            // php-src soap.c do_request — always call_user_function("__doRequest") (#31876).
            $response = self::dispatchDoRequest(
                $object,
                $request,
                $location,
                $action,
                $state->soapVersion,
                $frame,
                false
            );
            $state->lastResponse = $response;

            // php-src php_packet_soap.c Header walk → &$outputHeaders (even if Body later faults).
            if (null !== $outputHeadersVar) {
                self::writeOutputHeaders($outputHeadersVar, $response, $ctx);
            }

            $decoded = self::decodeResponse(
                $response,
                $name,
                $state->features,
                $state->classmap,
                $state->typemap,
                $state->elementTypes,
                $state->operationOutputParts,
                $state->complexTypeFields
            );

            // php-src soap.c — Z_CLIENT_LAST_* after traced __soapCall (#23925).
            self::syncTraceProperties($object, $state);

            return self::importValue($decoded, $ctx);
        } catch (\SoapFault $e) {
            // Trace bags may hold last request even when the call faults (#23925).
            self::syncTraceProperties($object, $state);
            $faultVar = BuiltinExceptionSupport::materializeSoapFault(
                $ctx,
                $e->getMessage(),
                '',
                0,
                (string) ($e->faultcode ?? ''),
                '' !== ($e->faultstring ?? '') ? (string) $e->faultstring : $e->getMessage(),
                (string) ($e->faultactor ?? ''),
                $e->detail ?? null,
                (string) ($e->_name ?? '')
            );
            // php-src soap.c — Z_CLIENT_SOAP_FAULT (#23925).
            self::syncSoapFaultProperty($object, $faultVar);
            // php-src: SoapFault return value thrown only when exceptions != false (#20293).
            if ($state->exceptions) {
                throw $e;
            }

            return $faultVar;
        }
    }

    /**
     * php-src soap.c do_request — invoke instance __doRequest so subclass overrides run (#31876).
     */
    public static function dispatchDoRequest(
        ObjectEntry $object,
        string $request,
        string $location,
        string $action,
        int $version,
        Frame $frame,
        bool $oneWay = false
    ): string {
        $vm = $frame->vmContext->runtime->vm ?? null;
        if (null === $vm) {
            return self::transportDoRequestWithOneWay(
                $object,
                $request,
                $location,
                $action,
                $version,
                $frame,
                $oneWay
            );
        }
        $req = new Variable();
        $req->string($request);
        $loc = new Variable();
        $loc->string($location);
        $act = new Variable();
        $act->string($action);
        $ver = new Variable();
        $ver->int($version);
        $ow = new Variable();
        $ow->bool($oneWay);
        $ret = $vm->invokeInstanceMethod($object, '__doRequest', $req, $loc, $act, $ver, $ow)->resolveIndirect();
        if (Variable::TYPE_NULL === $ret->type) {
            return '';
        }

        return $ret->toString();
    }

    /**
     * php-src PHP_METHOD(SoapClient, __doRequest) — oneWay skips the body unless WAIT (#31876).
     */
    public static function transportDoRequestWithOneWay(
        ObjectEntry $object,
        string $request,
        string $location,
        string $action,
        int $version,
        ?Frame $frame,
        bool $oneWay
    ): string {
        $body = self::doRequest($object, $request, $location, $action, $version, $frame);
        if (
            $oneWay
            && 0 === (self::state($object)->features & SoapConstants::SOAP_WAIT_ONE_WAY_CALLS)
        ) {
            return '';
        }

        return $body;
    }

    public static function doRequest(
        ObjectEntry $object,
        string $request,
        string $location,
        string $action,
        int $version,
        ?Frame $frame = null,
        bool $digestRetry = false
    ): string {
        $state = self::state($object);
        $state->lastRequest = $request;

        [$bodyOut, $contentEncoding, $acceptEncoding] = self::applyRequestCompression($state, $request);

        $cookieHeader = self::formatCookieHeader($state->cookies, $location);
        $authHeader = self::formatAuthorizationHeader($state, $location);
        $proxyAuthHeader = self::formatProxyAuthorizationHeader($state);
        $useProxy = self::usesHttpProxy($state, $location);
        $requestHeaders = self::buildHttpRequestHeaders(
            $location,
            $action,
            \strlen($bodyOut),
            $cookieHeader,
            $authHeader,
            $acceptEncoding,
            $contentEncoding,
            $proxyAuthHeader,
            $useProxy,
            $state->userAgent,
            $state->keepAlive,
            $state->streamContextOptions,
            $version
        );
        if ($state->trace) {
            $state->lastRequestHeaders = $requestHeaders;
        }

        $path = self::localPathFromLocation($location);
        if (null !== $path) {
            // Fixture Digest challenge sidecar (php-src 401 WWW-Authenticate path) (#20340).
            // Only when SOAP_AUTHENTICATION_DIGEST — Basic login fixtures share the same
            // response file and must keep Authorization: Basic (#20312).
            $challengePath = $path.'.digest-challenge';
            if (
                !$digestRetry
                && SoapConstants::SOAP_AUTHENTICATION_DIGEST === $state->authentication
                && null === $state->digest
                && null !== $state->login
                && null !== $state->password
                && \is_file($challengePath)
            ) {
                $challengeLine = \trim((string) \file_get_contents($challengePath));
                if ('' !== $challengeLine && self::ingestDigestChallenge($state, $challengeLine)) {
                    $state->lastResponseHeaders = "HTTP/1.1 401 Unauthorized\r\n".
                        'WWW-Authenticate: '.$challengeLine."\r\n";

                    return self::doRequest($object, $request, $location, $action, $version, $frame, true);
                }
            }
            $body = @\file_get_contents($path);
            if (false === $body) {
                throw new \SoapFault('HTTP', 'Could not read SOAP response fixture: '.$path);
            }
            $state->lastResponse = $body;
            if ($state->trace) {
                $state->lastResponseHeaders = self::synthesizeFixtureResponseHeaders(\strlen($body));
            }
            // php-src — sync stub __last_* after fixture transport (#23925).
            self::syncTraceProperties($object, $state);

            return $body;
        }

        if ('' === $location) {
            throw new \SoapFault('Client', 'SoapClient location is not set');
        }

        // php-src php_http.c — stream POST + Z_CLIENT_HTTPSOCKET keep-alive (#24913 / #26166).
        if (SoapHttpTransport::canHandle($location, $useProxy)) {
            [$body, $responseHeaders] = SoapHttpTransport::post(
                $object,
                $state,
                $location,
                $requestHeaders,
                $bodyOut,
                $useProxy
            );
        } else {
            // Legacy host HTTP wrapper (missing stream_socket_client).
            $useSsl = (bool) \preg_match('#^https://#i', $location);
            $headers = ($state->keepAlive ? "Connection: Keep-Alive\r\n" : "Connection: close\r\n").
                self::contentTypeAndActionHeaders($version, $action);
            if ('' !== $acceptEncoding) {
                $headers .= 'Accept-Encoding: '.$acceptEncoding."\r\n";
            }
            if ('' !== $contentEncoding) {
                $headers .= 'Content-Encoding: '.$contentEncoding."\r\n";
            }
            if ('' !== $cookieHeader) {
                $headers .= 'Cookie: '.$cookieHeader."\r\n";
            }
            if ('' !== $authHeader) {
                $headers .= $authHeader."\r\n";
            }
            // php-src: Proxy-Authorization on POST only when use_proxy && !use_ssl (#20339 / #26166).
            if ($useProxy && !$useSsl && '' !== $proxyAuthHeader) {
                $headers .= $proxyAuthHeader."\r\n";
            }
            $contextHeaderExtra = self::streamContextHttpHeaderBlock(
                $state->streamContextOptions,
                '' !== $authHeader,
                $useProxy && !$useSsl && '' !== $proxyAuthHeader,
                '' !== $cookieHeader
            );
            if ('' !== $contextHeaderExtra) {
                $headers .= $contextHeaderExtra;
            }
            $httpOpts = [
                'method' => 'POST',
                'header' => $headers,
                'content' => $bodyOut,
                'ignore_errors' => true,
                'timeout' => null !== $state->connectionTimeout ? $state->connectionTimeout : 30,
            ];
            // Merge user stream_context http/ssl options (php-src http_connect) (#20365).
            $httpOpts = self::mergeStreamContextHttpOptions($httpOpts, $state->streamContextOptions);
            if ($useProxy && null !== $state->proxyHost && null !== $state->proxyPort) {
                // php-src http_connect via proxy — PHP stream proxy URI (#20339).
                $httpOpts['proxy'] = 'tcp://'.$state->proxyHost.':'.$state->proxyPort;
                $httpOpts['request_fulluri'] = true;
            }
            $ctxOpts = ['http' => $httpOpts];
            if (isset($state->streamContextOptions['ssl']) && \is_array($state->streamContextOptions['ssl'])) {
                $ctxOpts['ssl'] = $state->streamContextOptions['ssl'];
            }
            // php-src php_http.c: ssl_method → STREAM_CRYPTO_METHOD_*_CLIENT (#20366).
            if (null !== $state->sslMethod && \preg_match('#^https://#i', $location)) {
                $crypto = self::sslMethodToCryptoMethod($state->sslMethod);
                if (null !== $crypto) {
                    if (!isset($ctxOpts['ssl']) || !\is_array($ctxOpts['ssl'])) {
                        $ctxOpts['ssl'] = [];
                    }
                    if (!\array_key_exists('crypto_method', $ctxOpts['ssl'])) {
                        $ctxOpts['ssl']['crypto_method'] = $crypto;
                    }
                }
            }
            $ctx = \stream_context_create($ctxOpts);
            SoapHttpTransport::closeSocket($object, $state);
            $body = @\file_get_contents($location, false, $ctx);
            if (false === $body) {
                throw new \SoapFault('HTTP', 'Could not connect to host');
            }
            $responseHeaders = '';
            if (isset($http_response_header) && \is_array($http_response_header) && $http_response_header !== []) {
                $responseHeaders = \implode("\r\n", $http_response_header)."\r\n";
            }
        }
        // php-src php_http.c — merge Set-Cookie into Z_CLIENT_COOKIES (#31843).
        if ('' !== $responseHeaders) {
            self::ingestSetCookiesFromResponseHeaders($object, $state, $responseHeaders, $location);
        }
        // php-src: HTTP 401 + WWW-Authenticate Digest → store challenge and retry (#20340).
        if (
            !$digestRetry
            && null === $state->digest
            && null !== $state->login
            && null !== $state->password
            && self::responseIsUnauthorized($responseHeaders)
            && self::ingestDigestChallengeFromHeaders($state, $responseHeaders)
        ) {
            $state->lastResponseHeaders = $responseHeaders;

            return self::doRequest($object, $request, $location, $action, $version, $frame, true);
        }
        $body = self::maybeDecompressResponse($body, $responseHeaders);
        $state->lastResponse = $body;
        if ($state->trace) {
            if ('' !== $responseHeaders) {
                $state->lastResponseHeaders = $responseHeaders;
            } else {
                $state->lastResponseHeaders = self::synthesizeFixtureResponseHeaders(\strlen($body));
            }
        }
        // php-src php_http.c: attach Soap\Url on successful HTTP connect (#23246 / #24913).
        self::attachHttpUrl($object, $location);
        // php-src — sync stub __last_* after HTTP transport (#23925).
        self::syncTraceProperties($object, $state);

        return $body;
    }

    /**
     * php-src php_http.c — Z_CLIENT_HTTPURL gets Soap\Url after successful stream connect (#23246).
     * Attaches parsed php_url payload for keep-alive host/port/scheme compare (#23926).
     * Z_CLIENT_HTTPSOCKET is attached by {@see SoapHttpTransport} (#24913).
     */
    private static function attachHttpUrl(ObjectEntry $object, string $location): void
    {
        if (!SoapExtensionPolicy::advertisesOpaqueUrlSdlTypes()) {
            return;
        }
        if (!\preg_match('#^https?://#i', $location)) {
            return;
        }
        if (!$object->hasProperty('httpurl')) {
            return;
        }
        $state = self::state($object);
        $ctx = $state->vmContext;
        if (null === $ctx) {
            return;
        }
        $payload = SoapUrlPayload::fromLocation($location);
        $object->getProperty('httpurl')->object(VmSoapOpaque::newUrlObject($ctx, $payload));
    }

    /**
     * php-src soap.c SoapClient ctor — Z_CLIENT_SDL gets Soap\Sdl after WSDL load (#23247).
     * Attaches parsed SDL snapshot on the opaque object (php-src sdl_object->sdl; #23905).
     */
    private static function attachSdl(ObjectEntry $object, Context $ctx, SoapClientState $state): void
    {
        if (!SoapExtensionPolicy::advertisesOpaqueUrlSdlTypes()) {
            return;
        }
        if (!$object->hasProperty('sdl')) {
            return;
        }
        $object->getProperty('sdl')->object(
            VmSoapOpaque::newSdlObject($ctx, SoapSdlPayload::fromClientState($state))
        );
    }

    /**
     * php-src soap.c SoapClient ctor — Z_CLIENT_TYPEMAP is array (was resource pre-8.4) (#23903).
     *
     * @param list<array{type_ns: string, type_name: string, from_xml: string|Variable|null, to_xml: string|Variable|null}> $typemap
     */
    private static function attachTypemap(ObjectEntry $object, Context $ctx, array $typemap): void
    {
        if (!SoapExtensionPolicy::advertisesOpaqueUrlSdlTypes()) {
            return;
        }
        if (!$object->hasProperty('typemap')) {
            return;
        }
        if ([] === $typemap) {
            return;
        }
        // Preserve Closure/callable Variables — cannot round-trip via JSON (#31845).
        $outer = new HashTable();
        $i = 0;
        foreach ($typemap as $entry) {
            $row = new HashTable();
            $ns = new Variable();
            $ns->string($entry['type_ns']);
            $row->add('type_ns', $ns);
            $tn = new Variable();
            $tn->string($entry['type_name']);
            $row->add('type_name', $tn);
            foreach (['from_xml', 'to_xml'] as $cbKey) {
                $cb = $entry[$cbKey];
                if (null === $cb) {
                    continue;
                }
                $slot = new Variable();
                if (\is_string($cb)) {
                    $slot->string($cb);
                } else {
                    $slot->copyFrom($cb);
                }
                $row->add($cbKey, $slot);
            }
            $rowVar = new Variable();
            $rowVar->array($row);
            $outer->addIndex($i, $rowVar);
            ++$i;
        }
        $object->getProperty('typemap')->array($outer);
    }

    /**
     * php-src php_http.c request compression (#20313).
     *
     * @return array{0: string, 1: string, 2: string} body, Content-Encoding value, Accept-Encoding value
     */
    private static function applyRequestCompression(SoapClientState $state, string $request): array
    {
        if (null === $state->compression) {
            return [$request, '', ''];
        }
        $flags = $state->compression;
        $level = $flags & 0x0f;
        if ($level > 9) {
            $level = 9;
        }
        $accept = '';
        if (0 !== ($flags & SoapConstants::SOAP_COMPRESSION_ACCEPT)) {
            $accept = 'gzip, deflate';
        }
        $contentEncoding = '';
        $body = $request;
        if ($level > 0) {
            if (0 !== ($flags & SoapConstants::SOAP_COMPRESSION_DEFLATE)) {
                $compressed = \gzcompress($request, $level);
                if (false !== $compressed) {
                    $body = $compressed;
                    $contentEncoding = 'deflate';
                }
            } else {
                $compressed = \gzencode($request, $level);
                if (false !== $compressed) {
                    $body = $compressed;
                    $contentEncoding = 'gzip';
                }
            }
        }

        return [$body, $contentEncoding, $accept];
    }

    private static function maybeDecompressResponse(string $body, string $responseHeaders): string
    {
        if ('' === $responseHeaders || !\preg_match('/^Content-Encoding:\s*(\S+)/im', $responseHeaders, $m)) {
            return $body;
        }
        $enc = \strtolower($m[1]);
        if ('gzip' === $enc || 'x-gzip' === $enc) {
            $out = \gzdecode($body);
            return false !== $out ? $out : $body;
        }
        if ('deflate' === $enc) {
            $out = \gzuncompress($body);
            if (false === $out) {
                // Some servers send raw deflate (RFC 1951) — try inflate.
                $out = @\gzinflate($body);
            }

            return false !== $out ? $out : $body;
        }

        return $body;
    }

    /**
     * Cookie header from jar — php-src php_http.c (#31569 / #31844).
     *
     * Each cookie is an array; index 0 is the value. Optional path (1), domain (2),
     * and secure (3) gate emission (path prefix, in_domain, SSL-or-not-secure).
     *
     * @param array<string, array<int, mixed>|string> $cookies
     */
    private static function formatCookieHeader(array $cookies, string $location = ''): string
    {
        if ($cookies === []) {
            return '';
        }
        $payload = '' !== $location ? SoapUrlPayload::fromLocation($location) : null;
        $uriPath = null !== $payload && null !== $payload->path && '' !== $payload->path
            ? $payload->path
            : '/';
        $uriHost = null !== $payload && null !== $payload->host ? $payload->host : '';
        $useSsl = (bool) \preg_match('#^https://#i', $location);

        $parts = [];
        foreach ($cookies as $name => $value) {
            if (\is_array($value)) {
                if (!isset($value[0]) || !\is_string($value[0])) {
                    continue;
                }
                // php-src: path prefix match (strncmp) when index 1 is a string (#31844).
                if (isset($value[1]) && \is_string($value[1])) {
                    $cookiePath = $value[1];
                    if (0 !== \strncmp($uriPath, $cookiePath, \strlen($cookiePath))) {
                        continue;
                    }
                }
                // php-src: in_domain(uri->host, domain) when index 2 is a string (#31844).
                if (isset($value[2]) && \is_string($value[2])) {
                    if (!self::cookieInDomain($uriHost, $value[2])) {
                        continue;
                    }
                }
                // php-src: skip secure cookies on non-SSL (#31844).
                if (!$useSsl && \array_key_exists(3, $value) && $value[3]) {
                    continue;
                }
                $parts[] = $name.'='.$value[0];
            } elseif (\is_string($value)) {
                // Legacy flat jar (pre-#31569).
                $parts[] = $name.'='.$value;
            }
        }
        if ($parts === []) {
            return '';
        }

        return \implode('; ', $parts);
    }

    /**
     * php-src php_http.c in_domain — leading '.' ⇒ suffix match, else exact (#31844).
     */
    private static function cookieInDomain(string $host, string $domain): bool
    {
        if ('' === $domain) {
            return true;
        }
        if (isset($domain[0]) && '.' === $domain[0]) {
            return \str_ends_with($host, $domain);
        }

        return $host === $domain;
    }

    /**
     * php-src php_http.c Set-Cookie: loop — merge into Z_CLIENT_COOKIES (#31843).
     *
     * Index 0 = value; 1 = path; 2 = domain; 3 = secure bool. Missing path/domain
     * default from request URI (dirname of path; host).
     */
    private static function ingestSetCookiesFromResponseHeaders(
        ObjectEntry $object,
        SoapClientState $state,
        string $responseHeaders,
        string $location
    ): void {
        if ('' === $responseHeaders || !\preg_match_all('/^Set-Cookie:\s*(.+)$/im', $responseHeaders, $matches)) {
            return;
        }
        $payload = SoapUrlPayload::fromLocation($location);
        $uriPath = null !== $payload && null !== $payload->path && '' !== $payload->path
            ? $payload->path
            : '/';
        $uriHost = null !== $payload && null !== $payload->host ? $payload->host : '';
        // php-src default path: URI path up to (not including) last '/'.
        $pos = \strrpos($uriPath, '/');
        $defaultPath = false === $pos ? '' : \substr($uriPath, 0, $pos);

        $changed = false;
        foreach ($matches[1] as $cookieLine) {
            $cookieLine = \trim((string) $cookieLine);
            if ('' === $cookieLine) {
                continue;
            }
            $eqpos = \strpos($cookieLine, '=');
            $sempos = \strpos($cookieLine, ';');
            if (false === $eqpos || (false !== $sempos && $sempos < $eqpos)) {
                continue;
            }
            $name = \substr($cookieLine, 0, $eqpos);
            if ('' === $name) {
                continue;
            }
            if (false !== $sempos) {
                $cookieValue = \substr($cookieLine, $eqpos + 1, $sempos - ($eqpos + 1));
            } else {
                $cookieValue = \substr($cookieLine, $eqpos + 1);
            }
            $zcookie = [0 => $cookieValue];
            if (false !== $sempos) {
                $options = \substr($cookieLine, $sempos + 1);
                while ('' !== $options) {
                    $options = \ltrim($options);
                    if ('' === $options) {
                        break;
                    }
                    $nextSem = \strpos($options, ';');
                    $token = false === $nextSem ? $options : \substr($options, 0, $nextSem);
                    if (0 === \strncasecmp($token, 'path=', 5)) {
                        $zcookie[1] = \substr($token, 5);
                    } elseif (0 === \strncasecmp($token, 'domain=', 7)) {
                        $zcookie[2] = \substr($token, 7);
                    } elseif (0 === \strncasecmp($token, 'secure', 6)) {
                        $zcookie[3] = true;
                    }
                    if (false === $nextSem) {
                        break;
                    }
                    $options = \substr($options, $nextSem + 1);
                }
            }
            if (!\array_key_exists(1, $zcookie)) {
                $zcookie[1] = $defaultPath;
            }
            if (!\array_key_exists(2, $zcookie) && '' !== $uriHost) {
                $zcookie[2] = $uriHost;
            }
            $state->cookies[$name] = $zcookie;
            $changed = true;
        }
        if ($changed && null !== $state->vmContext) {
            self::syncCookiesProperty($object, $state, $state->vmContext);
        }
    }

    /**
     * HTTP Authorization header line (no trailing CRLF) — php-src php_http.c (#20312, #20340).
     *
     * Digest when challenge params are stored; Basic when login set and not DIGEST-only mode
     * without a challenge yet (SOAP_AUTHENTICATION_DIGEST suppresses Basic until challenge).
     */
    private static function formatAuthorizationHeader(SoapClientState $state, string $location = ''): string
    {
        if (null === $state->login) {
            return '';
        }
        if (null !== $state->digest) {
            return self::formatDigestAuthorizationHeader($state, $location);
        }
        if (SoapConstants::SOAP_AUTHENTICATION_DIGEST === $state->authentication) {
            // php-src basic_authentication: skip Basic when _use_digest (#20340).
            return '';
        }
        $user = $state->login;
        $pass = null !== $state->password ? $state->password : '';

        return 'Authorization: Basic '.\base64_encode($user.':'.$pass);
    }

    /**
     * Authorization: Digest … — php-src php_http.c digest branch (#20340).
     *
     * @param array<string, string|int> $digest
     */
    private static function formatDigestAuthorizationHeader(SoapClientState $state, string $location): string
    {
        $digest = $state->digest;
        if (null === $digest || null === $state->login) {
            return '';
        }
        $user = $state->login;
        $pass = null !== $state->password ? $state->password : '';
        $realm = isset($digest['realm']) ? (string) $digest['realm'] : '';
        $nonce = isset($digest['nonce']) ? (string) $digest['nonce'] : '';
        $qop = isset($digest['qop']) ? (string) $digest['qop'] : '';
        $opaque = isset($digest['opaque']) ? (string) $digest['opaque'] : '';
        $algorithm = isset($digest['algorithm']) ? (string) $digest['algorithm'] : '';

        $ncInt = isset($digest['nc']) ? (int) $digest['nc'] + 1 : 1;
        $digest['nc'] = $ncInt;
        $state->digest = $digest;
        $nc = \sprintf('%08d', $ncInt);

        // php-src: 16 random bytes → hex, but only first 8 hex chars used in header/HA1-sess.
        try {
            $cnonceFull = \bin2hex(\random_bytes(16));
        } catch (\Throwable $e) {
            $cnonceFull = \bin2hex(\pack('d*', \microtime(true), \mt_rand()));
        }
        $cnonce = \substr($cnonceFull, 0, 8);

        $ha1 = \md5($user.':'.$realm.':'.$pass);
        if (0 === \strcasecmp($algorithm, 'md5-sess')) {
            $ha1 = \md5($ha1.':'.$nonce.':'.$cnonce);
        }

        $uriPath = self::digestUriPath($location);
        $ha2 = \md5('POST:'.$uriPath);

        if ('' !== $qop) {
            $response = \md5($ha1.':'.$nonce.':'.$nc.':'.$cnonce.':auth:'.$ha2);
        } else {
            $response = \md5($ha1.':'.$nonce.':'.$ha2);
        }

        $hdr = 'Authorization: Digest username="'.$user.'"';
        if ('' !== $realm) {
            $hdr .= ', realm="'.$realm.'"';
        }
        if ('' !== $nonce) {
            $hdr .= ', nonce="'.$nonce.'"';
        }
        $hdr .= ', uri="'.$uriPath.'"';
        if ('' !== $qop) {
            $hdr .= ', qop=auth, nc='.$nc.', cnonce="'.$cnonce.'"';
        }
        $hdr .= ', response="'.$response.'"';
        if ('' !== $opaque) {
            $hdr .= ', opaque="'.$opaque.'"';
        }
        if ('' !== $algorithm) {
            $hdr .= ', algorithm="'.$algorithm.'"';
        }

        return $hdr;
    }

    private static function digestUriPath(string $location): string
    {
        if (\preg_match('#^https?://[^/]+(/.*)?$#i', $location, $m)) {
            return isset($m[1]) && '' !== $m[1] ? $m[1] : '/';
        }

        return '/' !== $location && '' !== $location ? $location : '/';
    }

    private static function responseIsUnauthorized(string $responseHeaders): bool
    {
        return 1 === \preg_match('/^HTTP\/\d(?:\.\d)?\s+401\b/im', $responseHeaders);
    }

    private static function ingestDigestChallengeFromHeaders(SoapClientState $state, string $responseHeaders): bool
    {
        if (!\preg_match('/^WWW-Authenticate:\s*(.+)$/im', $responseHeaders, $m)) {
            return false;
        }

        return self::ingestDigestChallenge($state, \trim($m[1]));
    }

    /**
     * Parse "Digest realm=…, nonce=…" into SoapClientState::$digest (php-src 401 handler).
     */
    private static function ingestDigestChallenge(SoapClientState $state, string $authLine): bool
    {
        if (!\str_starts_with($authLine, 'Digest')) {
            return false;
        }
        $s = \substr($authLine, \strlen('Digest'));
        $digest = [];
        $len = \strlen($s);
        $i = 0;
        while ($i < $len) {
            while ($i < $len && ' ' === $s[$i]) {
                ++$i;
            }
            if ($i >= $len) {
                break;
            }
            $nameStart = $i;
            while ($i < $len && '=' !== $s[$i]) {
                ++$i;
            }
            if ($i >= $len || '=' !== $s[$i]) {
                break;
            }
            $name = \substr($s, $nameStart, $i - $nameStart);
            ++$i;
            if ($i < $len && '"' === $s[$i]) {
                ++$i;
                $valStart = $i;
                while ($i < $len && '"' !== $s[$i]) {
                    ++$i;
                }
                $val = \substr($s, $valStart, $i - $valStart);
                if ($i < $len) {
                    ++$i;
                }
            } else {
                $valStart = $i;
                while ($i < $len && ' ' !== $s[$i] && ',' !== $s[$i]) {
                    ++$i;
                }
                $val = \substr($s, $valStart, $i - $valStart);
            }
            while ($i < $len && ',' !== $s[$i] && ' ' !== $s[$i]) {
                ++$i;
            }
            while ($i < $len && (',' === $s[$i] || ' ' === $s[$i])) {
                ++$i;
            }
            $digest[$name] = $val;
        }
        if ($digest === []) {
            return false;
        }
        $state->digest = $digest;
        $state->authentication = SoapConstants::SOAP_AUTHENTICATION_DIGEST;

        return true;
    }

    /**
     * Proxy-Authorization Basic — php-src php_http.c proxy_authentication (#20339).
     */
    private static function formatProxyAuthorizationHeader(SoapClientState $state): string
    {
        if (null === $state->proxyLogin) {
            return '';
        }
        $user = $state->proxyLogin;
        $pass = null !== $state->proxyPassword ? $state->proxyPassword : '';

        return 'Proxy-Authorization: Basic '.\base64_encode($user.':'.$pass);
    }

    /**
     * php-src use_proxy when proxy_host (string) + proxy_port (long) are set (#20339 / #26166).
     * HTTPS still uses the proxy (CONNECT); Proxy-Authorization is omitted from the POST
     * header block and sent on CONNECT instead (php_http.c).
     */
    private static function usesHttpProxy(SoapClientState $state, string $location): bool
    {
        return null !== $state->proxyHost && null !== $state->proxyPort;
    }

    /**
     * Build Zend-shaped HTTP request header block for trace (php-src soap_client).
     */
    /**
     * @param array<string, mixed>|null $streamContextOptions
     */
    private static function buildHttpRequestHeaders(
        string $location,
        string $action,
        int $contentLength,
        string $cookieHeader = '',
        string $authHeader = '',
        string $acceptEncoding = '',
        string $contentEncoding = '',
        string $proxyAuthHeader = '',
        bool $useProxy = false,
        ?string $userAgent = null,
        bool $keepAlive = true,
        ?array $streamContextOptions = null,
        int $soapVersion = SoapConstants::SOAP_1_1
    ): string {
        $path = '/';
        $host = 'localhost';
        $useSsl = (bool) \preg_match('#^https://#i', $location);
        if (\preg_match('#^https?://([^/]+)(/.*)?$#i', $location, $m)) {
            $host = $m[1];
            $path = isset($m[2]) && '' !== $m[2] ? $m[2] : '/';
            // php-src: POST absolute-URI when use_proxy && !use_ssl (#20339 / #26166).
            if ($useProxy && !$useSsl) {
                $path = $location;
            }
        } elseif ('' !== $location) {
            $path = $location;
        }

        // php-src php_http.c: keep_alive false → Connection: close (#20364).
        $connection = $keepAlive ? 'Keep-Alive' : 'close';
        $hdr = 'POST '.$path." HTTP/1.1\r\n".
            'Host: '.$host."\r\n".
            'Connection: '.$connection."\r\n";
        // php-src: custom user_agent, else PHP-SOAP/VERSION (#20341).
        if (null !== $userAgent) {
            if ('' !== $userAgent) {
                $hdr .= 'User-Agent: '.$userAgent."\r\n";
            }
        } else {
            $hdr .= 'User-Agent: PHP-SOAP/'.\PHP_VERSION."\r\n";
        }
        if ('' !== $acceptEncoding) {
            $hdr .= 'Accept-Encoding: '.$acceptEncoding."\r\n";
        }
        if ('' !== $contentEncoding) {
            $hdr .= 'Content-Encoding: '.$contentEncoding."\r\n";
        }
        $hdr .= self::contentTypeAndActionHeaders($soapVersion, $action).
            'Content-Length: '.$contentLength."\r\n";
        if ('' !== $cookieHeader) {
            $hdr .= 'Cookie: '.$cookieHeader."\r\n";
        }
        if ('' !== $authHeader) {
            $hdr .= $authHeader."\r\n";
        }
        // php-src: Proxy-Authorization only when use_proxy && !use_ssl (#20339 / #26166).
        if ($useProxy && !$useSsl && '' !== $proxyAuthHeader) {
            $hdr .= $proxyAuthHeader."\r\n";
        }
        // php-src http_context_headers — merge stream_context http.header (#20365).
        $hdr .= self::streamContextHttpHeaderBlock(
            $streamContextOptions,
            '' !== $authHeader,
            $useProxy && !$useSsl && '' !== $proxyAuthHeader,
            '' !== $cookieHeader
        );

        return $hdr;
    }

    /**
     * php-src php_http.c Content-Type / SOAPAction vs SOAP 1.2 action param (#31918).
     */
    private static function contentTypeAndActionHeaders(int $soapVersion, string $action): string
    {
        if (SoapConstants::SOAP_1_2 === $soapVersion) {
            $hdr = 'Content-Type: application/soap+xml; charset=utf-8';
            if ('' !== $action) {
                $hdr .= '; action="'.$action.'"';
            }

            return $hdr."\r\n";
        }

        return "Content-Type: text/xml; charset=utf-8\r\n".
            'SOAPAction: "'.$action."\"\r\n";
    }

    /**
     * php-src http_context_headers / http_context_add_header (#20365).
     *
     * @param array<string, mixed>|null $streamContextOptions
     */
    private static function streamContextHttpHeaderBlock(
        ?array $streamContextOptions,
        bool $hasAuthorization,
        bool $hasProxyAuthorization,
        bool $hasCookies
    ): string {
        if (null === $streamContextOptions) {
            return '';
        }
        $http = $streamContextOptions['http'] ?? null;
        if (!\is_array($http) || !isset($http['header'])) {
            return '';
        }
        $raw = $http['header'];
        $lines = [];
        if (\is_array($raw)) {
            foreach ($raw as $item) {
                if (\is_string($item) && '' !== $item) {
                    $lines[] = $item;
                }
            }
        } elseif (\is_string($raw) && '' !== $raw) {
            $lines[] = $raw;
        }
        $out = '';
        foreach ($lines as $block) {
            foreach (\preg_split('/\r\n|\n|\r/', $block) ?: [] as $line) {
                $line = \trim($line);
                if ('' === $line) {
                    continue;
                }
                $lower = \strtolower($line);
                if ($hasAuthorization && \str_starts_with($lower, 'authorization:')) {
                    continue;
                }
                if ($hasProxyAuthorization && \str_starts_with($lower, 'proxy-authorization:')) {
                    continue;
                }
                if ($hasCookies && \str_starts_with($lower, 'cookie:')) {
                    continue;
                }
                $out .= $line."\r\n";
            }
        }

        return $out;
    }

    /**
     * Merge user stream_context http options under SOAP-owned keys (#20365).
     *
     * @param array<string, mixed>      $httpOpts
     * @param array<string, mixed>|null $streamContextOptions
     *
     * @return array<string, mixed>
     */
    private static function mergeStreamContextHttpOptions(array $httpOpts, ?array $streamContextOptions): array
    {
        if (null === $streamContextOptions || !isset($streamContextOptions['http']) || !\is_array($streamContextOptions['http'])) {
            return $httpOpts;
        }
        foreach ($streamContextOptions['http'] as $key => $value) {
            if ('header' === $key || 'method' === $key || 'content' === $key) {
                continue;
            }
            if (!\array_key_exists($key, $httpOpts)) {
                $httpOpts[$key] = $value;
            }
        }

        return $httpOpts;
    }

    /**
     * php-src php_http.c ssl_method → STREAM_CRYPTO_METHOD_*_CLIENT (#20366).
     */
    private static function sslMethodToCryptoMethod(int $sslMethod): ?int
    {
        return match ($sslMethod) {
            SoapConstants::SOAP_SSL_METHOD_TLS => \defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')
                ? (int) \constant('STREAM_CRYPTO_METHOD_TLS_CLIENT') : null,
            SoapConstants::SOAP_SSL_METHOD_SSLv2 => \defined('STREAM_CRYPTO_METHOD_SSLv2_CLIENT')
                ? (int) \constant('STREAM_CRYPTO_METHOD_SSLv2_CLIENT') : null,
            SoapConstants::SOAP_SSL_METHOD_SSLv3 => \defined('STREAM_CRYPTO_METHOD_SSLv3_CLIENT')
                ? (int) \constant('STREAM_CRYPTO_METHOD_SSLv3_CLIENT') : null,
            SoapConstants::SOAP_SSL_METHOD_SSLv23 => \defined('STREAM_CRYPTO_METHOD_SSLv23_CLIENT')
                ? (int) \constant('STREAM_CRYPTO_METHOD_SSLv23_CLIENT') : null,
            default => \defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')
                ? (int) \constant('STREAM_CRYPTO_METHOD_TLS_CLIENT') : null,
        };
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
        $cacheMode = $state->cacheWsdl;
        $xml = SoapWsdlCache::get($wsdl, $cacheMode);
        $fromCache = null !== $xml;
        if (null === $xml) {
            $xml = @\file_get_contents($wsdl);
        }
        if (false === $xml || null === $xml) {
            throw new \SoapFault('WSDL', 'SOAP-ERROR: Parsing WSDL: Couldn\'t load from \''.$wsdl.'\'');
        }
        if (!$fromCache) {
            SoapWsdlCache::put($wsdl, $xml, $cacheMode);
        }
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xml)) {
            throw new \SoapFault('WSDL', 'SOAP-ERROR: Parsing WSDL: Couldn\'t load from \''.$wsdl.'\'');
        }
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('wsdl', 'http://schemas.xmlsoap.org/wsdl/');
        $xpath->registerNamespace('soap', 'http://schemas.xmlsoap.org/wsdl/soap/');
        $xpath->registerNamespace('xsd', 'http://www.w3.org/2001/XMLSchema');

        // Message + schema element indexes first — needed for function_to_string (#31473).
        $messages = [];
        foreach ($xpath->query('//wsdl:message') ?: [] as $msg) {
            if (!$msg instanceof \DOMElement) {
                continue;
            }
            $msgName = $msg->getAttribute('name');
            if ('' !== $msgName) {
                $messages[self::xsdLocalName($msgName)] = $msg;
            }
        }
        $schemaElements = [];
        foreach ($xpath->query('//xsd:schema/xsd:element[@name]') ?: [] as $el) {
            if (!$el instanceof \DOMElement) {
                continue;
            }
            $elName = $el->getAttribute('name');
            if ('' !== $elName) {
                $schemaElements[$elName] = $el;
            }
        }

        foreach ($xpath->query('//wsdl:portType/wsdl:operation') ?: [] as $op) {
            if (!$op instanceof \DOMElement) {
                continue;
            }
            $name = $op->getAttribute('name');
            if ('' === $name) {
                continue;
            }
            // php-src soap.c function_to_string — display strings for __getFunctions (#31473 / #31570).
            $state->functions[] = self::wsdlFunctionToString($op, $name, $messages, $schemaElements);
            $state->functionIndex[\strtolower($name)] = $name;
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
            if (!$type instanceof \DOMElement) {
                continue;
            }
            $n = $type->getAttribute('name');
            if ('' === $n) {
                continue;
            }
            // php-src soap.c type_to_string — struct descriptions for __getTypes (#21089).
            $state->types[] = self::wsdlTypeToString($type, $n);
        }
        // Element-inline anonymous complexType/simpleType — php-src SDL type_to_string (#31474 / re-#21089).
        foreach ($schemaElements as $elName => $el) {
            foreach ($el->childNodes as $child) {
                if (!$child instanceof \DOMElement) {
                    continue;
                }
                $local = $child->localName ?? $child->nodeName;
                if ('complexType' !== $local && 'simpleType' !== $local) {
                    continue;
                }
                // Named nested types are already emitted above via //@name queries.
                if ('' !== $child->getAttribute('name')) {
                    continue;
                }
                $state->types[] = self::wsdlTypeToString($child, $elName);
                break;
            }
        }
        // php-src SDL element → type_str bindings for classmap without xsi:type (#21088).
        foreach ($xpath->query('//xsd:element') ?: [] as $el) {
            if (!$el instanceof \DOMElement) {
                continue;
            }
            $elName = $el->getAttribute('name');
            $elType = $el->getAttribute('type');
            if ('' === $elName || '' === $elType) {
                continue;
            }
            $typeLocal = self::xsdLocalName($elType);
            if ('' !== $typeLocal) {
                $state->elementTypes[$elName] = $typeLocal;
            }
        }
        // Named complexType field → type map for nested decode without xsi:type (#21091).
        foreach ($xpath->query('//xsd:complexType[@name]') ?: [] as $type) {
            if (!$type instanceof \DOMElement) {
                continue;
            }
            $typeName = $type->getAttribute('name');
            if ('' === $typeName) {
                continue;
            }
            $fields = self::wsdlComplexTypeFields($type);
            if ([] !== $fields) {
                $state->complexTypeFields[$typeName] = $fields;
            }
        }
        // Operation → output message part/element child types (document/literal SDL) (#21091).
        foreach ($xpath->query('//wsdl:portType/wsdl:operation') ?: [] as $op) {
            if (!$op instanceof \DOMElement) {
                continue;
            }
            $opName = $op->getAttribute('name');
            if ('' === $opName) {
                continue;
            }
            $opKey = \strtolower($opName);
            foreach (['output' => 'operationOutputParts', 'input' => 'operationInputParts'] as $io => $prop) {
                $ioEl = null;
                foreach ($op->childNodes as $child) {
                    if ($child instanceof \DOMElement && $io === ($child->localName ?? $child->nodeName)) {
                        $ioEl = $child;
                        break;
                    }
                }
                if (!$ioEl instanceof \DOMElement) {
                    continue;
                }
                $msgRef = $ioEl->getAttribute('message');
                if ('' === $msgRef) {
                    continue;
                }
                $msg = $messages[self::xsdLocalName($msgRef)] ?? null;
                if (!$msg instanceof \DOMElement) {
                    continue;
                }
                $parts = self::wsdlMessagePartFields($msg, $schemaElements);
                if ([] !== $parts) {
                    $state->{$prop}[$opKey] = $parts;
                }
            }
        }
        // WSDL soap:binding style + soap:body use when ctor did not set them (#21132).
        if (!$state->styleFromOptions || !$state->useFromOptions) {
            foreach ($xpath->query('//soap:binding') ?: [] as $binding) {
                if (!$binding instanceof \DOMElement) {
                    continue;
                }
                if (!$state->styleFromOptions) {
                    $styleAttr = \strtolower($binding->getAttribute('style'));
                    if ('document' === $styleAttr) {
                        $state->style = SoapConstants::SOAP_DOCUMENT;
                    } elseif ('rpc' === $styleAttr) {
                        $state->style = SoapConstants::SOAP_RPC;
                    }
                }
                break;
            }
            if (!$state->useFromOptions) {
                foreach ($xpath->query('//soap:body') ?: [] as $body) {
                    if (!$body instanceof \DOMElement) {
                        continue;
                    }
                    $useAttr = \strtolower($body->getAttribute('use'));
                    if ('literal' === $useAttr) {
                        $state->use = SoapConstants::SOAP_LITERAL;
                    } elseif ('encoded' === $useAttr) {
                        $state->use = SoapConstants::SOAP_ENCODED;
                    }
                    break;
                }
            }
        }
    }

    /**
     * Resolve wsdl:message parts to child element/name → type map (#21091 / #21131).
     *
     * @param array<string, \DOMElement> $schemaElements
     * @return array<string, string>
     */
    private static function wsdlMessagePartFields(\DOMElement $msg, array $schemaElements): array
    {
        $parts = [];
        foreach ($msg->childNodes as $part) {
            if (!$part instanceof \DOMElement || 'part' !== ($part->localName ?? $part->nodeName)) {
                continue;
            }
            $elRef = $part->getAttribute('element');
            if ('' === $elRef) {
                $partType = $part->getAttribute('type');
                $partName = $part->getAttribute('name');
                if ('' !== $partName && '' !== $partType) {
                    $parts[$partName] = self::xsdLocalName($partType);
                }
                continue;
            }
            $elDef = $schemaElements[self::xsdLocalName($elRef)] ?? null;
            if (!$elDef instanceof \DOMElement) {
                continue;
            }
            // Document/literal: unwrap element sequence into part child → type.
            foreach (self::wsdlElementSequenceFields($elDef) as $childName => $childType) {
                $parts[$childName] = $childType;
            }
        }

        return $parts;
    }

    /**
     * Direct sequence/all/choice members of a named complexType (#21091).
     *
     * @return array<string, string> field local-name → type local-name
     */
    private static function wsdlComplexTypeFields(\DOMElement $type): array
    {
        $fields = [];
        foreach ($type->getElementsByTagNameNS('http://www.w3.org/2001/XMLSchema', 'element') as $el) {
            if (!$el instanceof \DOMElement) {
                continue;
            }
            $elName = $el->getAttribute('name');
            $elType = $el->getAttribute('type');
            if ('' === $elName || '' === $elType) {
                continue;
            }
            $parent = $el->parentNode;
            $underNestedComplex = false;
            while ($parent instanceof \DOMNode && $parent !== $type) {
                if ($parent instanceof \DOMElement) {
                    $pl = $parent->localName ?? $parent->nodeName;
                    if ('complexType' === $pl && $parent !== $type) {
                        $underNestedComplex = true;
                        break;
                    }
                }
                $parent = $parent->parentNode;
            }
            if ($underNestedComplex) {
                continue;
            }
            $fields[$elName] = self::xsdLocalName($elType);
        }

        return $fields;
    }

    /**
     * Sequence fields under a global element (inline complexType or @type reference) (#21091).
     *
     * @return array<string, string>
     */
    private static function wsdlElementSequenceFields(\DOMElement $el): array
    {
        $elType = $el->getAttribute('type');
        if ('' !== $elType) {
            // Whole element is a named type — treat as single synthetic part using element name.
            return [$el->getAttribute('name') => self::xsdLocalName($elType)];
        }
        $fields = [];
        foreach ($el->getElementsByTagNameNS('http://www.w3.org/2001/XMLSchema', 'element') as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $childName = $child->getAttribute('name');
            $childType = $child->getAttribute('type');
            if ('' === $childName || '' === $childType) {
                continue;
            }
            // Only first-level members of this element's inline complexType.
            $parent = $child->parentNode;
            $underNested = false;
            while ($parent instanceof \DOMNode && $parent !== $el) {
                if ($parent instanceof \DOMElement) {
                    $pl = $parent->localName ?? $parent->nodeName;
                    if ('complexType' === $pl) {
                        // Walk up: if this complexType is not the element's immediate CT, skip.
                        $ctParent = $parent->parentNode;
                        if ($ctParent !== $el) {
                            $underNested = true;
                        }
                        break;
                    }
                }
                $parent = $parent->parentNode;
            }
            if ($underNested) {
                continue;
            }
            $fields[$childName] = self::xsdLocalName($childType);
        }

        return $fields;
    }

    /**
     * php-src soap.c function_to_string — display string for SoapClient::__getFunctions (#31473, #31570).
     *
     * @param array<string, \DOMElement> $messages
     * @param array<string, \DOMElement> $schemaElements global xsd:element[@name]
     */
    private static function wsdlFunctionToString(
        \DOMElement $op,
        string $opName,
        array $messages,
        array $schemaElements = []
    ): string {
        $inputMsg = self::wsdlOperationMessage($op, 'input', $messages);
        $outputMsg = self::wsdlOperationMessage($op, 'output', $messages);
        $requestParams = null !== $inputMsg ? self::wsdlMessageEncodeParams($inputMsg, $schemaElements) : [];
        $responseParams = null !== $outputMsg ? self::wsdlMessageEncodeParams($outputMsg, $schemaElements) : [];

        $buf = '';
        $respCount = \count($responseParams);
        if ($respCount > 0) {
            if (1 === $respCount) {
                $buf .= $responseParams[0]['type'].' ';
            } else {
                $parts = [];
                foreach ($responseParams as $p) {
                    $parts[] = $p['type'].' $'.$p['name'];
                }
                $buf .= 'list('.\implode(', ', $parts).') ';
            }
        } else {
            $buf .= 'void ';
        }

        $buf .= $opName.'(';
        $reqParts = [];
        foreach ($requestParams as $p) {
            $reqParts[] = $p['type'].' $'.$p['name'];
        }
        $buf .= \implode(', ', $reqParts);
        $buf .= ')';

        return $buf;
    }

    /**
     * @param array<string, \DOMElement> $messages
     */
    private static function wsdlOperationMessage(
        \DOMElement $op,
        string $io,
        array $messages
    ): ?\DOMElement {
        $ioEl = null;
        foreach ($op->childNodes as $child) {
            if ($child instanceof \DOMElement && $io === ($child->localName ?? $child->nodeName)) {
                $ioEl = $child;
                break;
            }
        }
        if (!$ioEl instanceof \DOMElement) {
            return null;
        }
        $msgRef = $ioEl->getAttribute('message');
        if ('' === $msgRef) {
            return null;
        }

        return $messages[self::xsdLocalName($msgRef)] ?? null;
    }

    /**
     * SDL request/response params: type_str + paramName (php-src encode->details.type_str).
     *
     * Document/literal `element=` parts resolve through the global element to its `@type`
     * (or keep the element local name when the element has an inline complexType) (#31570).
     *
     * @param array<string, \DOMElement> $schemaElements
     *
     * @return list<array{type: string, name: string}>
     */
    private static function wsdlMessageEncodeParams(\DOMElement $msg, array $schemaElements = []): array
    {
        $params = [];
        foreach ($msg->childNodes as $part) {
            if (!$part instanceof \DOMElement || 'part' !== ($part->localName ?? $part->nodeName)) {
                continue;
            }
            $partName = $part->getAttribute('name');
            if ('' === $partName) {
                $partName = 'param';
            }
            $elRef = $part->getAttribute('element');
            $typeRef = $part->getAttribute('type');
            if ('' !== $elRef) {
                $elLocal = self::xsdLocalName($elRef);
                $typeStr = $elLocal;
                $elDef = $schemaElements[$elLocal] ?? null;
                if ($elDef instanceof \DOMElement) {
                    $elType = $elDef->getAttribute('type');
                    if ('' !== $elType) {
                        // php-src: encode type_str is the named XSD type, not the element name (#31570).
                        $typeStr = self::xsdLocalName($elType);
                    }
                }
            } elseif ('' !== $typeRef) {
                $typeStr = self::xsdLocalName($typeRef);
            } else {
                // Empty part (no element/type) — php-src omits from encode list → void / no args.
                continue;
            }
            if ('' === $typeStr) {
                $typeStr = 'UNKNOWN';
            }
            $params[] = ['type' => $typeStr, 'name' => $partName];
        }

        return $params;
    }

    /**
     * php-src soap.c type_to_string subset for named schema types (#21089).
     */
    private static function wsdlTypeToString(\DOMElement $type, string $name): string
    {
        $local = $type->localName ?? $type->nodeName;
        if ('simpleType' === $local) {
            $base = 'anyType';
            foreach ($type->getElementsByTagNameNS('http://www.w3.org/2001/XMLSchema', 'restriction') as $rest) {
                if ($rest instanceof \DOMElement) {
                    $b = $rest->getAttribute('base');
                    if ('' !== $b) {
                        $base = self::xsdLocalName($b);
                    }
                    break;
                }
            }

            return $base.' '.$name;
        }

        $fields = [];
        foreach ($type->getElementsByTagNameNS('http://www.w3.org/2001/XMLSchema', 'element') as $el) {
            if (!$el instanceof \DOMElement) {
                continue;
            }
            // Only direct sequence/all/choice members of this complexType (skip nested anonymous types' children
            // that are deeper than one model level by requiring the element to be under this type's first model).
            $elName = $el->getAttribute('name');
            if ('' === $elName) {
                continue;
            }
            // Skip elements that belong to a nested named/anonymous complexType inside this one.
            $parent = $el->parentNode;
            $underNestedComplex = false;
            while ($parent instanceof \DOMNode && $parent !== $type) {
                if ($parent instanceof \DOMElement) {
                    $pl = $parent->localName ?? $parent->nodeName;
                    if ('complexType' === $pl && $parent !== $type) {
                        $underNestedComplex = true;
                        break;
                    }
                }
                $parent = $parent->parentNode;
            }
            if ($underNestedComplex) {
                continue;
            }
            $elType = $el->getAttribute('type');
            $typeStr = '' !== $elType ? self::xsdLocalName($elType) : 'anyType';
            $fields[] = ' '.$typeStr.' '.$elName.';';
        }
        if ([] === $fields) {
            return 'struct '.$name." {\n}";
        }

        return 'struct '.$name." {\n".\implode("\n", $fields)."\n}";
    }

    private static function xsdLocalName(string $qname): string
    {
        $pos = \strrpos($qname, ':');

        return false !== $pos ? \substr($qname, $pos + 1) : $qname;
    }

    /** php-src php_soap.h SOAP_1_1_ENC_NAMESPACE / SOAP_1_2_ENC_NAMESPACE (#31919). */
    private static function encodingNamespace(int $soapVersion): string
    {
        return SoapConstants::SOAP_1_2 === $soapVersion
            ? SoapConstants::SOAP_1_2_ENC_NAMESPACE
            : SoapConstants::SOAP_1_1_ENC_NAMESPACE;
    }

    /**
     * @param list<mixed> $arguments
     * @param list<ObjectEntry> $inputHeaders
     */
    private static function buildRequest(
        SoapClientState $state,
        string $name,
        array $arguments,
        ?Context $ctx = null,
        ?string $callUri = null,
        array $inputHeaders = []
    ): string {
        $ns = (null !== $callUri && '' !== $callUri)
            ? $callUri
            : ($state->uri !== '' ? $state->uri : 'http://example.com/');
        $envelopeNs = SoapConstants::SOAP_1_2 === $state->soapVersion
            ? 'http://www.w3.org/2003/05/soap-envelope'
            : 'http://schemas.xmlsoap.org/soap/envelope/';
        $prefix = SoapConstants::SOAP_1_2 === $state->soapVersion ? 'env' : 'SOAP-ENV';

        $paramsXml = '';
        $args = $arguments;
        // Zend wraps document/literal params; RPC often uses a single array of named params.
        // Do not unwrap SoapVar property bags (enc_stype/enc_value) used by typemap to_xml (#21046).
        if (
            1 === \count($args)
            && \is_array($args[0])
            && !\array_is_list($args[0])
            && null === self::soapVarShape($args[0])
        ) {
            $args = $args[0];
        }
        // Map positional args onto WSDL input element sequence names (#21131).
        $inputNames = \array_keys($state->operationInputParts[\strtolower($name)] ?? []);
        if (
            [] !== $inputNames
            && \is_array($args)
            && \array_is_list($args)
        ) {
            $named = [];
            foreach ($args as $i => $value) {
                $paramName = $inputNames[$i] ?? ('param'.$i);
                $named[$paramName] = $value;
            }
            $args = $named;
        }
        if (\is_array($args) && !\array_is_list($args)) {
            foreach ($args as $key => $value) {
                $paramsXml .= self::encodeParam((string) $key, $value, $state, $ctx);
            }
        } else {
            $i = 0;
            foreach ($args as $value) {
                $paramsXml .= self::encodeParam('param'.$i, $value, $state, $ctx);
                ++$i;
            }
        }

        $headerXml = '';
        $headers = $inputHeaders;
        foreach ($state->soapHeaders as $hdr) {
            $headers[] = $hdr;
        }
        if ($headers !== []) {
            $headerXml = '  <'.$prefix.':Header>'."\n";
            foreach ($headers as $hdr) {
                $headerXml .= self::encodeSoapHeaderElement($hdr, $prefix, $state->soapVersion);
            }
            $headerXml .= '  </'.$prefix.':Header>'."\n";
        }

        $encNs = self::encodingNamespace($state->soapVersion);
        $encXmlnsPrefix = SoapConstants::SOAP_1_2 === $state->soapVersion ? 'enc' : 'SOAP-ENC';
        $encodingStyleAttr = '';
        if (SoapConstants::SOAP_ENCODED === $state->use) {
            // php-src soap.c serialize_function_call — env:encodingStyle + SOAP_1_2_ENC_NAMESPACE (#31919).
            $encodingStyleAttr = ' '.$prefix.':encodingStyle="'.$encNs.'"';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".
            '<'.$prefix.':Envelope xmlns:'.$prefix.'="'.$envelopeNs.'"'.
            ' xmlns:ns1="'.\htmlspecialchars($ns, \ENT_XML1).'"'.
            ' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'.
            ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'.
            ' xmlns:'.$encXmlnsPrefix.'="'.$encNs.'"'.
            $encodingStyleAttr.'>'."\n".
            $headerXml.
            '  <'.$prefix.':Body>'."\n".
            '    <ns1:'.$name.'>'.$paramsXml.'</ns1:'.$name.'>'."\n".
            '  </'.$prefix.':Body>'."\n".
            '</'.$prefix.':Envelope>';
    }

    private static function encodeSoapHeaderElement(ObjectEntry $header, string $prefix, int $soapVersion): string
    {
        $ns = $header->hasProperty('namespace')
            ? $header->getProperty('namespace')->resolveIndirect()->toString()
            : '';
        $name = $header->hasProperty('name')
            ? $header->getProperty('name')->resolveIndirect()->toString()
            : 'Header';
        $tag = \preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) ?: 'Header';
        $attrs = '';
        if ('' !== $ns) {
            $attrs .= ' xmlns="'.\htmlspecialchars($ns, \ENT_XML1).'"';
        }
        $attrs .= SoapHeaderXml::envelopeAttributeString($soapVersion, $prefix, $header);
        $inner = '';
        if ($header->hasProperty('data')) {
            $dataVar = $header->getProperty('data')->resolveIndirect();
            if (Variable::TYPE_NULL !== $dataVar->type) {
                $exported = VmJson::export($dataVar, null, null, null);
                if (\is_scalar($exported) || null === $exported) {
                    $inner = \htmlspecialchars((string) $exported, \ENT_XML1);
                } elseif (\is_array($exported)) {
                    foreach ($exported as $k => $v) {
                        $inner .= self::encodeParam(\is_int($k) ? 'item' : (string) $k, $v);
                    }
                }
            }
        }

        return '    <'.$tag.$attrs.'>'.$inner.'</'.$tag.'>'."\n";
    }

    private static function encodeParam(
        string $name,
        mixed $value,
        ?SoapClientState $state = null,
        ?Context $ctx = null
    ): string {
        $tag = \preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) ?: 'param';
        // SoapVar-shaped export + typemap to_xml (#21046; php_encoding.c to_xml_user).
        if (null !== $state && null !== $ctx && [] !== $state->typemap) {
            $soapVar = self::soapVarShape($value);
            if (null !== $soapVar) {
                $entry = self::findTypemapEntry(
                    $state->typemap,
                    $soapVar['stype'],
                    $soapVar['ns']
                );
                if (null !== $entry && null !== $entry['to_xml']) {
                    $xmlFrag = self::invokeTypemapToXml($ctx, $entry['to_xml'], $soapVar['value']);
                    if (null !== $xmlFrag && '' !== $xmlFrag) {
                        // to_xml returns a full element; wrap under the param tag name when needed.
                        if (\str_starts_with(\ltrim($xmlFrag), '<')) {
                            return $xmlFrag;
                        }

                        return '<'.$tag.'>'.\htmlspecialchars($xmlFrag, \ENT_XML1).'</'.$tag.'>';
                    }
                }
            }
        }
        if (null === $value) {
            return '<'.$tag.' xsi:nil="true"/>';
        }
        $literal = null !== $state && SoapConstants::SOAP_LITERAL === $state->use;
        if (\is_bool($value)) {
            if ($literal) {
                return '<'.$tag.'>'.($value ? 'true' : 'false').'</'.$tag.'>';
            }

            return '<'.$tag.' xsi:type="xsd:boolean">'.($value ? 'true' : 'false').'</'.$tag.'>';
        }
        if (\is_int($value)) {
            if ($literal) {
                return '<'.$tag.'>'.$value.'</'.$tag.'>';
            }

            return '<'.$tag.' xsi:type="xsd:int">'.$value.'</'.$tag.'>';
        }
        if (\is_float($value)) {
            if ($literal) {
                return '<'.$tag.'>'.$value.'</'.$tag.'>';
            }

            return '<'.$tag.' xsi:type="xsd:float">'.$value.'</'.$tag.'>';
        }
        if (\is_array($value)) {
            if (
                null !== $state
                && SoapConstants::SOAP_ENCODED === $state->use
                && \array_is_list($value)
            ) {
                return self::encodeSoapEncodedListArray($tag, $value, $state, $ctx);
            }
            $inner = '';
            foreach ($value as $k => $v) {
                $inner .= self::encodeParam(\is_int($k) ? 'item' : (string) $k, $v, $state, $ctx);
            }

            return '<'.$tag.'>'.$inner.'</'.$tag.'>';
        }
        if ($literal) {
            return '<'.$tag.'>'.\htmlspecialchars((string) $value, \ENT_XML1).'</'.$tag.'>';
        }

        return '<'.$tag.' xsi:type="xsd:string">'.\htmlspecialchars((string) $value, \ENT_XML1).'</'.$tag.'>';
    }

    /**
     * php-src ext/soap/php_encoding.c to_xml_array — SOAP_ENCODED list arrays (#21715).
     *
     * @param list<mixed> $value
     */
    private static function encodeSoapEncodedListArray(
        string $tag,
        array $value,
        SoapClientState $state,
        ?Context $ctx = null
    ): string {
        $inner = '';
        foreach ($value as $v) {
            $inner .= self::encodeParam('item', $v, $state, $ctx);
        }
        $count = \count($value);
        $itemXsd = self::guessSoapEncodedArrayItemType($value);
        $arrayType = $itemXsd.'['.$count.']';
        $attrs = ' SOAP-ENC:arrayType="'.\htmlspecialchars($arrayType, \ENT_XML1).'"';
        if (0 !== ($state->features & SoapConstants::SOAP_USE_XSI_ARRAY_TYPE)) {
            $attrs .= ' xsi:type="SOAP-ENC:Array"';
        }

        return '<'.$tag.$attrs.'>'.$inner.'</'.$tag.'>';
    }

    /**
     * @param list<mixed> $list
     */
    private static function guessSoapEncodedArrayItemType(array $list): string
    {
        if ([] === $list) {
            return 'xsd:ur-type';
        }
        $prev = null;
        foreach ($list as $el) {
            $t = self::soapScalarValueToXsdType($el);
            if (null === $prev) {
                $prev = $t;
            } elseif ($prev !== $t) {
                return 'xsd:ur-type';
            }
        }

        return 'xsd:anyType' === $prev ? 'xsd:ur-type' : ($prev ?? 'xsd:ur-type');
    }

    private static function soapScalarValueToXsdType(mixed $value): string
    {
        if (\is_bool($value)) {
            return 'xsd:boolean';
        }
        if (\is_int($value)) {
            return 'xsd:int';
        }
        if (\is_float($value)) {
            return 'xsd:float';
        }
        if (\is_string($value)) {
            return 'xsd:string';
        }
        if (\is_array($value)) {
            return 'xsd:anyType';
        }

        return 'xsd:anyType';
    }

    /**
     * Detect SoapVar property bag after VmJson export.
     *
     * @return array{stype: string, ns: string, value: mixed}|null
     */
    private static function soapVarShape(mixed $value): ?array
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        if (!\is_array($value)) {
            return null;
        }
        if (!\array_key_exists('enc_stype', $value) && !\array_key_exists('enc_value', $value)) {
            return null;
        }
        $stype = isset($value['enc_stype']) && \is_string($value['enc_stype']) ? $value['enc_stype'] : '';
        $ns = isset($value['enc_ns']) && \is_string($value['enc_ns']) ? $value['enc_ns'] : '';
        if ('' === $stype) {
            return null;
        }

        return [
            'stype' => $stype,
            'ns' => $ns,
            'value' => $value['enc_value'] ?? null,
        ];
    }

    /**
     * @param list<array{type_ns: string, type_name: string, from_xml: ?string, to_xml: ?string}> $typemap
     *
     * @return array{type_ns: string, type_name: string, from_xml: ?string, to_xml: ?string}|null
     */
    private static function findTypemapEntry(array $typemap, string $typeName, string $typeNs): ?array
    {
        foreach ($typemap as $entry) {
            if ($entry['type_name'] !== $typeName) {
                continue;
            }
            if ('' !== $entry['type_ns'] && '' !== $typeNs && $entry['type_ns'] !== $typeNs) {
                continue;
            }

            return $entry;
        }

        return null;
    }

    private static function invokeTypemapToXml(Context $ctx, string|Variable $callback, mixed $value): ?string
    {
        try {
            $arg = self::importJsonLike($value, $ctx);
            $result = self::invokeTypemapCallback($ctx, $callback, $arg);
        } catch (\Throwable) {
            return null;
        }
        $result = $result->resolveIndirect();
        if (Variable::TYPE_STRING === $result->type) {
            return $result->toString();
        }

        return null;
    }

    private static function invokeTypemapCallback(Context $ctx, string|Variable $callback, Variable $arg): Variable
    {
        if (\is_string($callback)) {
            $fn = VmUserCall::resolveStringCallback($ctx, $callback);

            return VmUserCall::invokeOne($ctx, $fn, $arg);
        }

        return VmCallable::invoke($ctx, $callback, $arg);
    }

    /**
     * @param array<string, string> $classmap type local-name → PHP class (no leading \)
     */
    private static function normalizeClassmap(array $raw): array
    {
        $out = [];
        foreach ($raw as $type => $class) {
            if (!\is_string($type) || '' === $type) {
                continue;
            }
            if (!\is_string($class) || '' === $class) {
                continue;
            }
            // php-src #69280 — strip leading backslash on FQCN values.
            $out[$type] = \ltrim($class, '\\');
        }

        return $out;
    }

    /**
     * @param array<mixed> $raw
     *
     * @return list<array{type_ns: string, type_name: string, from_xml: string|Variable|null, to_xml: string|Variable|null}>
     */
    private static function normalizeTypemap(array $raw): array
    {
        $out = [];
        foreach ($raw as $entry) {
            if ($entry instanceof \stdClass) {
                $entry = (array) $entry;
            }
            if (!\is_array($entry)) {
                continue;
            }
            $typeName = isset($entry['type_name']) && \is_string($entry['type_name'])
                ? $entry['type_name']
                : '';
            if ('' === $typeName) {
                continue;
            }
            $typeNs = isset($entry['type_ns']) && \is_string($entry['type_ns'])
                ? $entry['type_ns']
                : '';
            $fromXml = isset($entry['from_xml']) && \is_string($entry['from_xml']) && '' !== $entry['from_xml']
                ? $entry['from_xml']
                : null;
            $toXml = isset($entry['to_xml']) && \is_string($entry['to_xml']) && '' !== $entry['to_xml']
                ? $entry['to_xml']
                : null;
            if (null === $fromXml && null === $toXml) {
                continue;
            }
            $out[] = [
                'type_ns' => $typeNs,
                'type_name' => $typeName,
                'from_xml' => $fromXml,
                'to_xml' => $toXml,
            ];
        }

        return $out;
    }

    /**
     * Peel typemap before JSON export so Closures survive (#31845 / php-src ZVAL_COPY).
     *
     * @return list<array{type_ns: string, type_name: string, from_xml: string|Variable|null, to_xml: string|Variable|null}>
     */
    public static function normalizeTypemapFromVariable(Variable $typemapVar, Context $ctx): array
    {
        $typemapVar = $typemapVar->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $typemapVar->type) {
            return [];
        }
        $out = [];
        foreach ($typemapVar->toArray()->iterateKeyed(false) as [$_key, $entryVar]) {
            $entryVar = $entryVar->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $entryVar->type) {
                continue;
            }
            $ht = $entryVar->toArray();
            $typeNameSlot = $ht->find('type_name');
            if (null === $typeNameSlot) {
                continue;
            }
            $typeNameVar = $typeNameSlot->resolveIndirect();
            if (Variable::TYPE_STRING !== $typeNameVar->type || '' === $typeNameVar->toString()) {
                continue;
            }
            $typeNs = '';
            $typeNsSlot = $ht->find('type_ns');
            if (null !== $typeNsSlot) {
                $typeNsVar = $typeNsSlot->resolveIndirect();
                if (Variable::TYPE_STRING === $typeNsVar->type) {
                    $typeNs = $typeNsVar->toString();
                }
            }
            $fromXml = self::typemapCallbackFromSlot($ht->find('from_xml'), $ctx);
            $toXml = self::typemapCallbackFromSlot($ht->find('to_xml'), $ctx);
            if (null === $fromXml && null === $toXml) {
                continue;
            }
            $out[] = [
                'type_ns' => $typeNs,
                'type_name' => $typeNameVar->toString(),
                'from_xml' => $fromXml,
                'to_xml' => $toXml,
            ];
        }

        return $out;
    }

    private static function typemapCallbackFromSlot(?Variable $slot, Context $ctx): string|Variable|null
    {
        if (null === $slot) {
            return null;
        }
        $cb = $slot->resolveIndirect();
        if (Variable::TYPE_STRING === $cb->type) {
            $name = $cb->toString();

            return '' !== $name ? $name : null;
        }
        if (VmCallable::isCallable($ctx, $cb)) {
            $copy = new Variable();
            $copy->copyFrom($cb);

            return $copy;
        }

        return null;
    }

    /**
     * @param array<string, string>                                                                 $classmap
     * @param list<array{type_ns: string, type_name: string, from_xml: ?string, to_xml: ?string}> $typemap
     * @param array<string, string>                                                                 $elementTypes WSDL element local-name → type local-name (#21088)
     * @param array<string, array<string, string>>                                                  $operationOutputParts op → response child → type (#21091)
     * @param array<string, array<string, string>>                                                  $complexTypeFields type → field → type (#21091)
     */
    private static function decodeResponse(
        string $response,
        string $name,
        int $features = 0,
        array $classmap = [],
        array $typemap = [],
        array $elementTypes = [],
        array $operationOutputParts = [],
        array $complexTypeFields = []
    ): mixed {
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
            $detail = null;
            if ($faultEl instanceof \DOMElement) {
                foreach ($faultEl->childNodes as $child) {
                    if (!$child instanceof \DOMElement) {
                        continue;
                    }
                    $ln = $child->localName ?? $child->nodeName;
                    if ('Code' === $ln) {
                        // php-src php_packet_soap.c SOAP 1.2: Code/Value only (#32045).
                        $valueText = self::firstChildElementText($child, 'Value');
                        if (null !== $valueText) {
                            $code = $valueText;
                        }
                    } elseif ('faultcode' === $ln) {
                        $code = \trim($child->textContent);
                    }
                    if ('Reason' === $ln) {
                        // php-src php_packet_soap.c SOAP 1.2: first Reason/Text only (#32046).
                        $text = self::firstChildElementText($child, 'Text');
                        if (null !== $text) {
                            $string = $text;
                        }
                    } elseif ('faultstring' === $ln) {
                        $string = \trim($child->textContent);
                    }
                    if ('Detail' === $ln) {
                        // php-src php_packet_soap.c SOAP 1.2: master_to_zval(Detail) (#32047).
                        $detail = self::soapFaultDetailValue($child);
                    }
                }
            }
            throw new \SoapFault($code, $string, null, $detail);
        }

        $body = $xpath->query('//SOAP-ENV:Body/*|//env:Body/*');
        if (!$body || 0 === $body->length) {
            return null;
        }
        $responseEl = $body->item(0);
        if (!$responseEl instanceof \DOMElement) {
            return null;
        }

        // Operation-scoped output parts override flat global elementTypes (#21091; php_sdl.c).
        $scopedTypes = $elementTypes;
        $opKey = \strtolower($name);
        if (isset($operationOutputParts[$opKey])) {
            foreach ($operationOutputParts[$opKey] as $partName => $partType) {
                $scopedTypes[$partName] = $partType;
            }
        }

        $singleElementArrays = 0 !== ($features & SoapConstants::SOAP_SINGLE_ELEMENT_ARRAYS);
        $children = [];
        foreach ($responseEl->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $childName = $child->localName ?? $child->nodeName;
                $hint = $scopedTypes[$childName] ?? null;
                $children[$childName] = self::domElementToValue(
                    $child,
                    $singleElementArrays,
                    $classmap,
                    $typemap,
                    $scopedTypes,
                    $hint,
                    $complexTypeFields
                );
            }
        }
        if (0 === \count($children)) {
            return \trim($responseEl->textContent);
        }
        if (1 === \count($children)) {
            return \reset($children);
        }

        return self::maybeMappedObject($responseEl, $children, $classmap, $typemap, $scopedTypes, null);
    }

    /**
     * php-src get_node(children, name) text (php_packet_soap.c; #32045).
     */
    private static function firstChildElementText(\DOMElement $parent, string $localName): ?string
    {
        foreach ($parent->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $ln = $child->localName ?? $child->nodeName;
            if ($localName === $ln) {
                return \trim($child->textContent);
            }
        }

        return null;
    }

    /**
     * php-src master_to_zval on SOAP 1.2 Fault Detail (php_packet_soap.c; #32047).
     *
     * Named children become stdClass properties — do not apply the SOAP `item` list
     * heuristic used for encoded arrays.
     */
    private static function soapFaultDetailValue(\DOMElement $detailEl): mixed
    {
        $childElements = [];
        foreach ($detailEl->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $childElements[] = $child;
            }
        }
        if (0 === \count($childElements)) {
            $text = \trim($detailEl->textContent);

            return '' === $text ? null : $text;
        }
        $map = [];
        foreach ($childElements as $child) {
            $key = $child->localName ?? $child->nodeName;
            $map[$key] = self::domElementToValue($child);
        }

        return (object) $map;
    }

    /**
     * @param array<string, string>                                                                 $classmap
     * @param list<array{type_ns: string, type_name: string, from_xml: ?string, to_xml: ?string}> $typemap
     * @param array<string, string>                                                                 $elementTypes
     * @param array<string, array<string, string>>                                                  $complexTypeFields
     */
    private static function domElementToValue(
        \DOMElement $el,
        bool $singleElementArrays = false,
        array $classmap = [],
        array $typemap = [],
        array $elementTypes = [],
        ?string $hintType = null,
        array $complexTypeFields = []
    ): mixed {
        // typemap from_xml wins over structural decode (#21046; to_zval_user).
        $typemapHit = self::matchTypemapFromXml($el, $typemap, $elementTypes, $hintType);
        if (null !== $typemapHit) {
            return $typemapHit;
        }

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
        $parentFields = (null !== $hintType && isset($complexTypeFields[$hintType]))
            ? $complexTypeFields[$hintType]
            : [];
        foreach ($childElements as $child) {
            $key = $child->localName ?? $child->nodeName;
            // Prefer parent complexType field types over flat global element name map (#21091).
            $childHint = $parentFields[$key] ?? $elementTypes[$key] ?? null;
            if (isset($map[$key])) {
                if (!\is_array($map[$key]) || !\array_is_list($map[$key])) {
                    $map[$key] = [$map[$key]];
                }
                $map[$key][] = self::domElementToValue(
                    $child,
                    $singleElementArrays,
                    $classmap,
                    $typemap,
                    $elementTypes,
                    $childHint,
                    $complexTypeFields
                );
            } else {
                $map[$key] = self::domElementToValue(
                    $child,
                    $singleElementArrays,
                    $classmap,
                    $typemap,
                    $elementTypes,
                    $childHint,
                    $complexTypeFields
                );
            }
            if ('item' !== $key) {
                $list = false;
            }
        }
        if ($list) {
            return \array_values($map);
        }
        // php-src php_encoding.c SOAP_SINGLE_ELEMENT_ARRAYS — wrap singleton children (#20367).
        if ($singleElementArrays) {
            foreach ($map as $k => $v) {
                if (!\is_array($v) || !\array_is_list($v)) {
                    $map[$k] = [$v];
                }
            }
        }

        return self::maybeMappedObject($el, $map, $classmap, $typemap, $elementTypes, $hintType);
    }

    /**
     * Without xsi:type, WSDL element→type (SDL type_str) still applies (#21088 classmap, #21090 typemap).
     *
     * @param list<array{type_ns: string, type_name: string, from_xml: ?string, to_xml: ?string}> $typemap
     * @param array<string, string>                                                                 $elementTypes
     */
    private static function matchTypemapFromXml(
        \DOMElement $el,
        array $typemap,
        array $elementTypes = [],
        ?string $hintType = null
    ): ?SoapTypemapFromXml {
        if ([] === $typemap) {
            return null;
        }
        [$typeName, $typeNs] = self::xsiTypeNameAndNs($el);
        if (null === $typeName) {
            $typeName = self::resolveTypeLocalName($el, $elementTypes, $hintType);
            $typeNs = '';
        }
        if (null === $typeName) {
            return null;
        }
        $entry = self::findTypemapEntry($typemap, $typeName, $typeNs ?? '');
        if (null === $entry || null === $entry['from_xml']) {
            return null;
        }
        $xml = $el->ownerDocument !== null
            ? (string) $el->ownerDocument->saveXML($el)
            : $el->C14N();

        return new SoapTypemapFromXml($entry['from_xml'], $xml);
    }

    /**
     * php-src to_zval_object_ex: classmap keyed by type_str / xsi:type local name (#21044).
     * Without xsi:type, WSDL element→type (SDL type_str) still applies (#21088).
     *
     * @param array<string, mixed>                                                                  $props
     * @param array<string, string>                                                                 $classmap
     * @param list<array{type_ns: string, type_name: string, from_xml: ?string, to_xml: ?string}> $typemap
     * @param array<string, string>                                                                 $elementTypes
     */
    private static function maybeMappedObject(
        \DOMElement $el,
        array $props,
        array $classmap,
        array $typemap = [],
        array $elementTypes = [],
        ?string $hintType = null
    ): mixed {
        $typemapHit = self::matchTypemapFromXml($el, $typemap, $elementTypes, $hintType);
        if (null !== $typemapHit) {
            return $typemapHit;
        }
        if ([] !== $classmap) {
            $typeName = self::resolveTypeLocalName($el, $elementTypes, $hintType);
            if (null !== $typeName && isset($classmap[$typeName])) {
                return new SoapMappedObject($classmap[$typeName], $props);
            }
        }

        return (object) $props;
    }

    /**
     * Resolve SOAP type local-name: xsi:type wins, else WSDL/SDL hint, else elementTypes[localName].
     *
     * @param array<string, string> $elementTypes
     */
    private static function resolveTypeLocalName(
        \DOMElement $el,
        array $elementTypes,
        ?string $hintType
    ): ?string {
        $xsi = self::xsiTypeLocalName($el);
        if (null !== $xsi) {
            return $xsi;
        }
        if (null !== $hintType && '' !== $hintType) {
            return $hintType;
        }
        $local = $el->localName ?? $el->nodeName;
        if (isset($elementTypes[$local])) {
            return $elementTypes[$local];
        }

        return null;
    }

    /**
     * @return array{0: ?string, 1: ?string} [localName, namespaceURI]
     */
    private static function xsiTypeNameAndNs(\DOMElement $el): array
    {
        $xsi = $el->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type');
        if ('' === $xsi) {
            $xsi = $el->getAttribute('xsi:type');
        }
        if ('' === $xsi) {
            return [null, null];
        }
        $pos = \strrpos($xsi, ':');
        if (false !== $pos) {
            $prefix = \substr($xsi, 0, $pos);
            $local = \substr($xsi, $pos + 1);
            $ns = $el->lookupNamespaceURI($prefix);

            return [$local, $ns !== null && '' !== $ns ? $ns : null];
        }

        return [$xsi, null];
    }

    private static function xsiTypeLocalName(\DOMElement $el): ?string
    {
        [$local] = self::xsiTypeNameAndNs($el);

        return $local;
    }

    private static function importValue(mixed $value, Context $ctx): Variable
    {
        if ($value instanceof SoapTypemapFromXml) {
            return self::importTypemapFromXml($value, $ctx);
        }
        if ($value instanceof SoapMappedObject) {
            return self::importMappedObject($value, $ctx);
        }
        // Nested SoapMappedObject / typemap cannot survive json_encode — walk the tree (#21044).
        if ($value instanceof \stdClass || \is_array($value)) {
            return self::importDecodedTree($value, $ctx);
        }
        if (null === $value || \is_scalar($value)) {
            return self::importJsonLike($value, $ctx);
        }
        $var = new Variable();
        $var->string((string) $value);

        return $var;
    }

    private static function importTypemapFromXml(SoapTypemapFromXml $mapped, Context $ctx): Variable
    {
        $arg = new Variable();
        $arg->string($mapped->xml);

        return self::invokeTypemapCallback($ctx, $mapped->callback, $arg);
    }

    /**
     * @param \stdClass|array<mixed> $value
     */
    private static function importDecodedTree(mixed $value, Context $ctx): Variable
    {
        if ($value instanceof SoapTypemapFromXml) {
            return self::importTypemapFromXml($value, $ctx);
        }
        if ($value instanceof SoapMappedObject) {
            return self::importMappedObject($value, $ctx);
        }
        if ($value instanceof \stdClass) {
            if (!isset($ctx->classes['stdclass'])) {
                throw new \LogicException('stdClass is not registered');
            }
            $object = new ObjectEntry($ctx->classes['stdclass']);
            $object->constructed = true;
            foreach ((array) $value as $key => $item) {
                $object->allocateProperty((string) $key)
                    ->copyFrom(self::importValue($item, $ctx));
            }
            $var = new Variable();
            $var->object($object);

            return $var;
        }
        if (\is_array($value)) {
            $ht = new HashTable();
            $isList = \array_is_list($value);
            foreach ($value as $key => $item) {
                $slot = self::importValue($item, $ctx);
                if ($isList) {
                    $ht->addIndex((int) $key, $slot);
                } else {
                    $ht->add((string) $key, $slot);
                }
            }
            $var = new Variable();
            $var->array($ht);

            return $var;
        }

        return self::importJsonLike($value, $ctx);
    }

    private static function importMappedObject(SoapMappedObject $mapped, Context $ctx): Variable
    {
        $classLc = \strtolower($mapped->className);
        // php-src: zend_fetch_class failure → stay on stdClass (#21047).
        if (!isset($ctx->classes[$classLc])) {
            $std = new \stdClass();
            foreach ($mapped->properties as $key => $item) {
                $std->{$key} = $item;
            }

            return self::importDecodedTree($std, $ctx);
        }
        $ce = $ctx->classes[$classLc];
        $object = new ObjectEntry($ce);
        // php-src: constructor is not called for classmap hydration.
        $object->constructed = true;
        foreach ($mapped->properties as $key => $item) {
            $object->allocateProperty((string) $key)
                ->copyFrom(self::importValue($item, $ctx));
        }
        $var = new Variable();
        $var->object($object);

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
            throw new \TypeError('SoapClient::__soapCall(): Argument #2 ($args) must be of type array');
        }

        return self::exportArgTree($argsVar, $frame);
    }

    /**
     * Export __soapCall argv with SoapVar → enc_* bags for typemap to_xml (#21046).
     */
    private static function exportArgTree(Variable $var, ?Frame $frame): mixed
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT === $var->type) {
            $obj = $var->toObject();
            if (\strtolower($obj->class->name) === 'soapvar') {
                return [
                    'enc_type' => $obj->hasProperty('enc_type')
                        ? self::exportArgTree($obj->getProperty('enc_type'), $frame)
                        : null,
                    'enc_value' => $obj->hasProperty('enc_value')
                        ? self::exportArgTree($obj->getProperty('enc_value'), $frame)
                        : null,
                    'enc_stype' => $obj->hasProperty('enc_stype')
                        ? self::exportArgTree($obj->getProperty('enc_stype'), $frame)
                        : null,
                    'enc_ns' => $obj->hasProperty('enc_ns')
                        ? self::exportArgTree($obj->getProperty('enc_ns'), $frame)
                        : null,
                    'enc_name' => $obj->hasProperty('enc_name')
                        ? self::exportArgTree($obj->getProperty('enc_name'), $frame)
                        : null,
                    'enc_namens' => $obj->hasProperty('enc_namens')
                        ? self::exportArgTree($obj->getProperty('enc_namens'), $frame)
                        : null,
                ];
            }
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            $assoc = [];
            $list = [];
            $isList = true;
            $i = 0;
            foreach ($var->toArray()->iterateKeyed(true) as [$key, $value]) {
                $k = $key->resolveIndirect();
                $exported = self::exportArgTree($value, $frame);
                if (Variable::TYPE_INTEGER === $k->type && $k->toInt() === $i) {
                    $list[] = $exported;
                    ++$i;
                } else {
                    $isList = false;
                }
                if (Variable::TYPE_STRING === $k->type) {
                    $assoc[$k->toString()] = $exported;
                } elseif (Variable::TYPE_INTEGER === $k->type) {
                    $assoc[$k->toInt()] = $exported;
                }
            }

            return $isList ? $list : $assoc;
        }

        return VmJson::export($var, $frame?->vmContext ?? null, null, $frame);
    }
}

final class SoapClientState
{
    public ?string $wsdl = null;

    /** php-src cache_wsdl bitmask (WSDL_CACHE_*) (#26511). */
    public int $cacheWsdl = SoapConstants::WSDL_CACHE_DISK;

    /** Owning VM context — Soap\Url / Soap\Sdl factories (#23246). */
    public ?Context $vmContext = null;

    /** @var array<string, mixed> */
    public array $options = [];

    public string $location = '';

    public string $uri = '';

    public bool $trace = false;

    /** php-src Z_CLIENT_EXCEPTIONS — default true; false returns SoapFault (#20293). */
    public bool $exceptions = true;

    /** php-src _login / _password / _authentication (#20312). */
    public ?string $login = null;

    public ?string $password = null;

    public int $authentication = SoapConstants::SOAP_AUTHENTICATION_BASIC;

    /**
     * Parsed WWW-Authenticate Digest params (php-src _digest) (#20340).
     *
     * @var array<string, string|int>|null
     */
    public ?array $digest = null;

    /** php-src Z_CLIENT_COMPRESSION — null when unset (#20313). */
    public ?int $compression = null;

    /** php-src _proxy_host / _proxy_port / _proxy_login / _proxy_password (#20339). */
    public ?string $proxyHost = null;

    public ?int $proxyPort = null;

    public ?string $proxyLogin = null;

    public ?string $proxyPassword = null;

    /** php-src _user_agent — null means default PHP-SOAP/VERSION (#20341). */
    public ?string $userAgent = null;

    /** php-src _connection_timeout seconds — null means stream default (#20341). */
    public ?int $connectionTimeout = null;

    /** php-src _keep_alive — default true; false → Connection: close (#20364). */
    public bool $keepAlive = true;

    /**
     * php-src Z_CLIENT_HTTPSOCKET — VmFs stream handle for keep-alive HTTP (#24913).
     * Mirrored onto SoapClient::$httpsocket after successful connect.
     */
    public ?int $httpSocketHandle = null;

    /**
     * php-src stream_context wrapper options (http/ssl bags) (#20365).
     *
     * @var array<string, mixed>|null
     */
    public ?array $streamContextOptions = null;

    /** php-src _ssl_method — null when unset (#20366). */
    public ?int $sslMethod = null;

    /** php-src features bitmask (SOAP_SINGLE_ELEMENT_ARRAYS, …) (#20367). */
    public int $features = 0;

    /** True when ctor options supplied features (#23923). */
    public bool $featuresFromOptions = false;

    /** php-src _encoding — null when unset (#23923). */
    public ?string $encoding = null;

    /**
     * php-src _classmap — SOAP type local-name → PHP class name (no leading \) (#21044).
     *
     * @var array<string, string>
     */
    public array $classmap = [];

    /**
     * php-src typemap entries (#21046 / #31845).
     *
     * @var list<array{type_ns: string, type_name: string, from_xml: string|Variable|null, to_xml: string|Variable|null}>
     */
    public array $typemap = [];

    public int $soapVersion = SoapConstants::SOAP_1_1;

    public int $style = SoapConstants::SOAP_RPC;

    public int $use = SoapConstants::SOAP_ENCODED;

    /** True when ctor options supplied style (#21132). */
    public bool $styleFromOptions = false;

    /** True when ctor options supplied use (#21132). */
    public bool $useFromOptions = false;

    /** @var list<string> */
    public array $functions = [];

    /** @var array<string, string> */
    public array $functionIndex = [];

    /** @var list<string> */
    public array $types = [];

    /**
     * WSDL xsd:element name → type local-name (php-src SDL type_str for classmap) (#21088).
     *
     * @var array<string, string>
     */
    public array $elementTypes = [];

    /**
     * Operation (lowercase) → document/literal output part child → type local-name (#21091).
     *
     * @var array<string, array<string, string>>
     */
    public array $operationOutputParts = [];

    /**
     * Operation (lowercase) → document/literal input part child → type local-name (#21131).
     *
     * @var array<string, array<string, string>>
     */
    public array $operationInputParts = [];

    /**
     * Named complexType → field local-name → type local-name (#21091).
     *
     * @var array<string, array<string, string>>
     */
    public array $complexTypeFields = [];

    public string $lastRequest = '';

    public string $lastResponse = '';

    public ?string $lastRequestHeaders = null;

    public ?string $lastResponseHeaders = null;

    /** @var array<string, array<int, mixed>> name → [0=>value, 1=>path?, 2=>domain?, 3=>secure?] (#31569) */
    public array $cookies = [];

    /** @var list<ObjectEntry> */
    public array $soapHeaders = [];
}

/**
 * Decode-time stand-in for a classmap-hydrated struct (host PHP; imported to ObjectEntry).
 *
 * @internal
 */
final class SoapMappedObject
{
    /**
     * @param array<string, mixed> $properties
     */
    public function __construct(
        public readonly string $className,
        public readonly array $properties,
    ) {
    }
}

/**
 * Decode-time stand-in for typemap from_xml (php-src to_zval_user; #21046).
 *
 * @internal
 */
final class SoapTypemapFromXml
{
    public function __construct(
        public readonly string|Variable $callback,
        public readonly string $xml,
    ) {
    }
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
            // Peel stream_context / typemap before JSON export — resources/Closures are not JSON-safe (#20365 / #31845).
            $streamContextOptions = null;
            $typemapEntries = null;
            $sourceHt = $optVar->toArray();
            $scSlot = $sourceHt->find('stream_context');
            if (null !== $scSlot) {
                $scVar = $scSlot->resolveIndirect();
                if (VmStreamContext::isRepresentation($scVar)) {
                    $optsHt = VmStreamContext::getOptionsHashTable($scVar);
                    $optsVar = new Variable();
                    $optsVar->array($optsHt);
                    $exportedCtx = VmHttpBuildQuery::export($optsVar, $frame);
                    if (\is_array($exportedCtx)) {
                        $streamContextOptions = $exportedCtx;
                    }
                }
            }
            $tmSlot = $sourceHt->find('typemap');
            if (null !== $tmSlot) {
                $typemapEntries = VmSoapClient::normalizeTypemapFromVariable(
                    $tmSlot->resolveIndirect(),
                    $frame->vmContext
                );
            }
            $filtered = new HashTable();
            foreach ($sourceHt->iterateKeyed(true) as [$key, $value]) {
                $k = $key->resolveIndirect();
                if (Variable::TYPE_STRING === $k->type
                    && ('stream_context' === $k->toString() || 'typemap' === $k->toString())
                ) {
                    continue;
                }
                $copy = new Variable();
                $copy->copyFrom($value);
                if (Variable::TYPE_STRING === $k->type) {
                    $filtered->add($k->toString(), $copy);
                } elseif (Variable::TYPE_INTEGER === $k->type) {
                    $filtered->addIndex($k->toInt(), $copy);
                }
            }
            $exportVar = new Variable();
            $exportVar->array($filtered);
            $exported = VmJson::export($exportVar, $frame->vmContext, null, $frame);
            if (\is_array($exported)) {
                $options = $exported;
            }
            if (null !== $streamContextOptions) {
                $options['__phpc_stream_context_options'] = $streamContextOptions;
            }
            if (null !== $typemapEntries) {
                $options['__phpc_typemap'] = $typemapEntries;
            }
            // php-src soap.c: ssl_method option is deprecated (#20366).
            if (isset($options['ssl_method']) && (\is_int($options['ssl_method']) || \is_float($options['ssl_method']))) {
                $frame->vmContext->errors->triggerError(
                    'The "ssl_method" option is deprecated. Use "ssl" stream context options instead',
                    ErrorReporter::E_DEPRECATED,
                    '' !== $frame->scriptPath ? $frame->scriptPath : null,
                    $frame->vmContext,
                    $frame
                );
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
        if ($argc > 6) {
            throw new \ArgumentCountError(
                'SoapClient::__soapCall() expects at most 5 arguments, '.($argc - 1).' given'
            );
        }
        $receiver = $this->receiver($frame, 'SoapClient::__soapCall()');
        $name = $this->stringArg($frame->calledArgs[1], 'SoapClient::__soapCall', 0, 'name');
        $arguments = VmSoapClient::exportArguments($frame->calledArgs[2], $frame);
        if (!\is_array($arguments)) {
            $arguments = [];
        }
        $callOptions = null;
        // Sparse named args skip optionals (outputHeaders: $out without $options) (#31875).
        if (isset($frame->calledArgs[3])) {
            $optVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $optVar->type) {
                if (Variable::TYPE_ARRAY !== $optVar->type) {
                    throw new \TypeError(
                        'SoapClient::__soapCall(): Argument #3 ($options) must be of type ?array, '
                        .ReflectionSupport::valueTypeLabelPublic($optVar).' given'
                    );
                }
                $exported = VmSoapClient::exportArguments($optVar, $frame);
                $callOptions = \is_array($exported) ? $exported : [];
            }
        }
        $inputHeaders = [];
        if (isset($frame->calledArgs[4])) {
            $inputHeaders = VmSoapClient::parseInputHeadersArg(
                $frame->calledArgs[4],
                'SoapClient::__soapCall'
            );
        }
        $result = VmSoapClient::soapCall(
            $receiver,
            $name,
            $arguments,
            $frame->vmContext,
            $frame,
            $callOptions,
            $inputHeaders,
            $frame->calledArgs[5] ?? null
        );
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
        $oneWay = false;
        if (isset($frame->calledArgs[5])) {
            $oneWay = $frame->calledArgs[5]->resolveIndirect()->toBool();
        }
        $response = VmSoapClient::transportDoRequestWithOneWay(
            $receiver,
            $request,
            $location,
            $action,
            $version,
            $frame,
            $oneWay
        );
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
        foreach (VmSoapClient::getCookies($receiver) as $name => $entry) {
            // php-src returns nested jar arrays (index 0 = value) (#31569).
            $inner = new HashTable();
            if (\is_array($entry)) {
                foreach ($entry as $idx => $part) {
                    $slot = new Variable();
                    if (\is_string($part)) {
                        $slot->string($part);
                    } elseif (\is_int($part)) {
                        $slot->int($part);
                    } elseif (\is_bool($part)) {
                        $slot->bool($part);
                    } elseif (null === $part) {
                        $slot->null();
                    } else {
                        $slot->string((string) $part);
                    }
                    if (\is_int($idx)) {
                        $inner->addIndex($idx, $slot);
                    } else {
                        $inner->add((string) $idx, $slot);
                    }
                }
            }
            $outer = new Variable();
            $outer->array($inner);
            $ht->add((string) $name, $outer);
        }
        $frame->returnVar->array($ht);
    }
}

final class SoapClientSetLocation extends SoapClassMethod
{
    public function __construct()
    {
        parent::__construct('__setLocation');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'SoapClient::__setLocation() expects at most 1 argument, '.($argc - 1).' given'
            );
        }
        $receiver = $this->receiver($frame, 'SoapClient::__setLocation()');
        $location = '';
        if ($argc >= 2) {
            $locVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $locVar->type) {
                $location = $this->stringArg($frame->calledArgs[1], 'SoapClient::__setLocation', 0, 'location');
            }
        }
        $previous = VmSoapClient::setLocation($receiver, $location);
        if (null !== $frame->returnVar) {
            $frame->returnVar->string($previous);
        }
    }
}

final class SoapClientSetSoapHeaders extends SoapClassMethod
{
    public function __construct()
    {
        parent::__construct('__setSoapHeaders');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'SoapClient::__setSoapHeaders() expects at most 1 argument, '.($argc - 1).' given'
            );
        }
        $receiver = $this->receiver($frame, 'SoapClient::__setSoapHeaders()');
        $headers = [];
        if ($argc >= 2) {
            $arg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL === $arg->type) {
                $headers = [];
            } elseif (Variable::TYPE_OBJECT === $arg->type) {
                $obj = $arg->toObject();
                if ('soapheader' !== \strtolower($obj->class->name)) {
                    throw new \TypeError(
                        'SoapClient::__setSoapHeaders(): Argument #1 ($headers) must be of type SoapHeader|array|null'
                    );
                }
                $headers = [$obj];
            } elseif (Variable::TYPE_ARRAY === $arg->type) {
                foreach ($arg->toArray()->iterateKeyed(false) as $pair) {
                    $v = $pair[1]->resolveIndirect();
                    if (Variable::TYPE_OBJECT !== $v->type) {
                        throw new \TypeError(
                            'SoapClient::__setSoapHeaders(): Argument #1 ($headers) must be of type SoapHeader|array|null'
                        );
                    }
                    $obj = $v->toObject();
                    if ('soapheader' !== \strtolower($obj->class->name)) {
                        throw new \TypeError(
                            'SoapClient::__setSoapHeaders(): Argument #1 ($headers) must be of type SoapHeader|array|null'
                        );
                    }
                    $headers[] = $obj;
                }
            } else {
                throw new \TypeError(
                    'SoapClient::__setSoapHeaders(): Argument #1 ($headers) must be of type SoapHeader|array|null'
                );
            }
        }
        VmSoapClient::setSoapHeaders($receiver, $headers);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}
