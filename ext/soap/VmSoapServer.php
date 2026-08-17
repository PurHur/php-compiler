<?php

declare(strict_types=1);

namespace PHPCompiler\ext\soap;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal as InternalFunc;
use PHPCompiler\Func\PHP as PhpFunc;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\OutputBuffer;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmInternalCall;
use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmSession;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\ext\standard\VmUserCall;
use PHPCompiler\VM\MagicMethodInvocationAborted;
use PHPCompiler\VM\ScriptExit;
use PHPCompiler\Web\ResponseContext;

/**
 * SoapServer VM class — v1 string handle + addFunction/setObject (php-src ext/soap/soap.c; #20126, #20292).
 */
final class VmSoapServer
{
    public const CLASS_LC = 'soapserver';

    /** php-src soap.c session key for SOAP_PERSISTENCE_SESSION (#20315). */
    private const SESSION_OBJECT_KEY = '_bogus_session_name';

    /** @var array<int, SoapServerState> */
    private static array $store = [];

    /** Nesting depth of {@see handle()} — in-handler fault() must emit Fault XML (#20194). */
    private static int $handleDepth = 0;

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && isset($ctx->classes[self::CLASS_LC]->methods['handle'])) {
            return;
        }

        $entry = $ctx->classes[self::CLASS_LC] ?? new ClassEntry('SoapServer');
        $entry->isInternal = true;
        $pub = CfgFunc::FLAG_PUBLIC;

        $entry->constructor = new SoapServerConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;

        $methods = [
            'addfunction' => new SoapServerAddFunction(),
            'setclass' => new SoapServerSetClass(),
            'setobject' => new SoapServerSetObject(),
            'getfunctions' => new SoapServerGetFunctions(),
            'handle' => new SoapServerHandle(),
            'fault' => new SoapServerFault(),
            'addsoapheader' => new SoapServerAddSoapHeader(),
            'setpersistence' => new SoapServerSetPersistence(),
            '__getlastresponse' => new SoapServerGetLastResponse(),
        ];
        foreach ($methods as $lc => $method) {
            $entry->methods[$lc] = $method;
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['addfunction'] = 'addFunction';
        $entry->methodNames['setclass'] = 'setClass';
        $entry->methodNames['setobject'] = 'setObject';
        $entry->methodNames['getfunctions'] = 'getFunctions';
        $entry->methodNames['addsoapheader'] = 'addSoapHeader';
        $entry->methodNames['setpersistence'] = 'setPersistence';
        $entry->methodNames['__getlastresponse'] = '__getLastResponse';

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function initObject(ObjectEntry $object, ?string $wsdl, array $options): void
    {
        $state = new SoapServerState();
        $state->wsdl = $wsdl;
        $state->options = $options;
        $state->uri = isset($options['uri']) ? (string) $options['uri'] : '';
        $state->soapVersion = isset($options['soap_version'])
            ? (int) $options['soap_version']
            : SoapConstants::SOAP_1_1;
        if (null !== $wsdl && '' !== $wsdl) {
            self::loadWsdlFunctions($state, $wsdl);
        }
        self::$store[$object->id] = $state;
        $object->constructed = true;
    }

    public static function state(ObjectEntry $object): SoapServerState
    {
        if (!isset(self::$store[$object->id])) {
            throw new \SoapFault('Server', 'SoapServer object has not been correctly initialized by its constructor');
        }

        return self::$store[$object->id];
    }

    /**
     * @param list<string>|string $functions
     */
    public static function addFunction(ObjectEntry $object, $functions): void
    {
        $state = self::state($object);
        if (\is_string($functions)) {
            $functions = [$functions];
        }
        if (!\is_array($functions)) {
            throw new \TypeError('SoapServer::addFunction(): Argument #1 ($functions) must be of type array|string|int');
        }
        // php-src: string/array path clears functions_all and rebuilds ft (#20292).
        $state->functionsAll = false;
        foreach ($functions as $fn) {
            $name = (string) $fn;
            if ('' === $name) {
                continue;
            }
            $state->functions[] = $name;
            $state->functionIndex[\strtolower($name)] = $name;
        }
    }

    /**
     * SoapServer::addFunction(SOAP_FUNCTIONS_ALL) — enable EG(function_table) dispatch (php-src soap.c; #20292).
     */
    public static function addFunctionAll(ObjectEntry $object, Frame $frame): void
    {
        $state = self::state($object);
        if (version_compare(CompilerVersion::languageProfileVersion(), '8.4.0', '>=')
            && null !== $frame->vmContext
        ) {
            $frame->vmContext->errors->triggerError(
                'Enabling all functions via SOAP_FUNCTIONS_ALL is deprecated since 8.4, due to possible security concerns.'
                .' If all PHP functions should be enabled, the flattened return value of get_defined_functions() can be used',
                ErrorReporter::E_DEPRECATED,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $frame->vmContext,
                $frame
            );
        }
        $state->functions = [];
        $state->functionIndex = [];
        $state->functionsAll = true;
    }

    public static function setClass(ObjectEntry $object, string $className, array $ctorArgs = []): void
    {
        $state = self::state($object);
        $state->className = $className;
        $state->object = null;
        $state->classInstance = null;
        $state->classCtorArgs = $ctorArgs;
    }

    public static function setObject(ObjectEntry $object, ObjectEntry $service): void
    {
        $state = self::state($object);
        $state->object = $service;
        $state->className = null;
        $state->classInstance = null;
        $state->classCtorArgs = [];
        foreach ($service->class->methods as $lc => $_) {
            if (\str_starts_with($lc, '__')) {
                continue;
            }
            $display = $service->class->methodNames[$lc] ?? $lc;
            $state->functions[] = $display;
            $state->functionIndex[\strtolower($display)] = $display;
        }
    }

    public static function addSoapHeader(ObjectEntry $object, ObjectEntry $header): void
    {
        if ('soapheader' !== \strtolower($header->class->name)) {
            throw new \TypeError('SoapServer::addSoapHeader(): Argument #1 ($header) must be of type SoapHeader');
        }
        self::state($object)->responseHeaders[] = $header;
    }

    public static function setPersistence(ObjectEntry $object, int $mode): void
    {
        if (
            SoapConstants::SOAP_PERSISTENCE_SESSION !== $mode
            && SoapConstants::SOAP_PERSISTENCE_REQUEST !== $mode
        ) {
            throw new \SoapFault('Server', 'Invalid persistence mode');
        }
        $state = self::state($object);
        $state->persistence = $mode;
        if (SoapConstants::SOAP_PERSISTENCE_REQUEST === $mode) {
            $state->classInstance = null;
        }
    }

    /**
     * @return list<string>
     */
    public static function getFunctions(ObjectEntry $object, ?Context $ctx = null): array
    {
        $state = self::state($object);
        if ($state->functionsAll) {
            // php-src: ft = EG(function_table) when functions_all (#20292).
            if (null === $ctx) {
                return [];
            }
            $out = [];
            foreach ($ctx->functions as $fn) {
                $out[] = $fn->getName();
            }

            return $out;
        }

        return $state->functions;
    }

    public static function handle(ObjectEntry $object, ?string $request, Context $ctx, Frame $frame): void
    {
        $state = self::state($object);
        if (null === $request || '' === $request) {
            $request = '';
            // php://input not wired in VM v1 — empty request yields empty response.
            if ('' === $request) {
                $state->lastResponse = '';
                OutputBuffer::append('');

                return;
            }
        }

        $state->pendingFault = null;
        ++self::$handleDepth;
        try {
            $isFault = false;
            try {
                [$opName, $args] = self::parseRequest($request);
                $result = self::dispatch($object, $opName, $args, $ctx, $frame);
                if (null !== $state->pendingFault) {
                    $response = self::buildFaultFromPending($state);
                    $isFault = true;
                } else {
                    $response = self::buildResponse($state, $opName, $result);
                }
            } catch (\SoapFault $e) {
                // Prefer pendingFault: isolated dispatch rematerializes SoapFault as
                // new SoapFault($message) and drops faultcode (#20194).
                $isFault = true;
                $response = null !== $state->pendingFault
                    ? self::buildFaultFromPending($state)
                    : self::buildFaultResponse($state, $e);
            } catch (MagicMethodInvocationAborted $e) {
                if (null === $state->pendingFault) {
                    throw $e;
                }
                $isFault = true;
                $response = self::buildFaultFromPending($state);
            } catch (ScriptExit $e) {
                throw $e;
            } catch (\Throwable $e) {
                $isFault = true;
                if (null !== $state->pendingFault) {
                    $response = self::buildFaultFromPending($state);
                } else {
                    $sf = new \SoapFault('Server', $e->getMessage());
                    $response = self::buildFaultResponse($state, $sf);
                }
            }

            $state->lastResponse = $response;
            // php-src soap_server_fault_ex / serialize_response_call sapi_add_header (#31957).
            self::emitHandleTransportHeaders($state, $ctx, $isFault);
            OutputBuffer::append($response);
        } finally {
            // php-src: SESSION persistence object must remain in $_SESSION for
            // session_encode / next-request decode (#20315, #20342).
            if (
                SoapConstants::SOAP_PERSISTENCE_SESSION === $state->persistence
                && null !== $state->classInstance
            ) {
                self::storeSessionClassInstance($ctx, $state->classInstance);
            }
            --self::$handleDepth;
            $state->pendingFault = null;
        }
    }

    /**
     * php-src soap.c soap_server_fault_ex / success serialize_response_call sapi_add_header (#31957).
     *
     * SOAP 1.2: Content-Type application/soap+xml; charset=utf-8
     * SOAP 1.1: Content-Type text/xml; charset=utf-8
     * Faults: HTTP/1.1 500 unless $_SERVER['HTTP_USER_AGENT'] === "Shockwave Flash".
     */
    private static function emitHandleTransportHeaders(
        SoapServerState $state,
        Context $ctx,
        bool $isFault
    ): void {
        if ($isFault && self::shouldUseHttpErrorStatus($ctx)) {
            ResponseContext::addHeader('HTTP/1.1 500 Internal Server Error');
        }
        ResponseContext::addHeader(self::responseContentTypeHeader($state->soapVersion));
    }

    /** php-src soap.c Content-Type for SOAP 1.1 vs 1.2 server responses. */
    public static function responseContentTypeHeader(int $soapVersion): string
    {
        if (SoapConstants::SOAP_1_2 === $soapVersion) {
            return 'Content-Type: application/soap+xml; charset=utf-8';
        }

        return 'Content-Type: text/xml; charset=utf-8';
    }

    /**
     * php-src soap_server_fault_ex: skip HTTP 500 when User-Agent is exactly "Shockwave Flash".
     */
    private static function shouldUseHttpErrorStatus(Context $ctx): bool
    {
        return 'Shockwave Flash' !== self::httpUserAgent($ctx);
    }

    private static function httpUserAgent(Context $ctx): string
    {
        $server = $ctx->getSuperglobal('_SERVER');
        if (null !== $server && Variable::TYPE_ARRAY === $server->type) {
            $uaVar = $server->toArray()->find('HTTP_USER_AGENT');
            if (null !== $uaVar && Variable::TYPE_STRING === $uaVar->type) {
                return $uaVar->toString();
            }
        }
        if (isset($_SERVER['HTTP_USER_AGENT']) && \is_string($_SERVER['HTTP_USER_AGENT'])) {
            return $_SERVER['HTTP_USER_AGENT'];
        }

        return '';
    }

    public static function fault(
        ObjectEntry $object,
        string $code,
        string $string,
        string $actor = '',
        mixed $details = null,
        string $name = '',
        string $lang = ''
    ): void {
        $state = self::state($object);
        // Zend soap_server_fault_ex: in-handler fault is serialized into the response
        // buffer; outside handle() the SoapFault escapes to user code (#20194, #20219).
        if (self::$handleDepth > 0) {
            $state->pendingFault = [
                'code' => $code,
                'string' => $string,
                'actor' => $actor,
                'details' => $details,
                'name' => $name,
                'lang' => $lang,
            ];
        }
        throw new \SoapFault($code, $string, '' !== $actor ? $actor : null, $details, '' !== $name ? $name : null);
    }

    /**
     * @return array{0: string, 1: list<Variable>}
     */
    private static function parseRequest(string $request): array
    {
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($request)) {
            throw new \SoapFault('Client', 'Bad Request');
        }
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('SOAP-ENV', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xpath->registerNamespace('env', 'http://www.w3.org/2003/05/soap-envelope');

        $bodyOps = $xpath->query('//SOAP-ENV:Body/*|//env:Body/*');
        if (!$bodyOps || 0 === $bodyOps->length) {
            throw new \SoapFault('Client', 'Bad Request: no SOAP body');
        }
        $opEl = $bodyOps->item(0);
        if (!$opEl instanceof \DOMElement) {
            throw new \SoapFault('Client', 'Bad Request: no SOAP body');
        }
        $opName = $opEl->localName ?? $opEl->nodeName;
        $args = [];
        foreach ($opEl->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $args[] = self::domToVariable($child);
        }

        return [$opName, $args];
    }

    private static function domToVariable(\DOMElement $el): Variable
    {
        $childElements = [];
        foreach ($el->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $childElements[] = $child;
            }
        }
        $var = new Variable();
        if (0 === \count($childElements)) {
            if ($el->hasAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'nil')) {
                $var->null();

                return $var;
            }
            $text = \trim($el->textContent);
            $xsiType = $el->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'type');
            if (\str_contains($xsiType, 'int') || \str_contains($xsiType, 'integer')) {
                $var->int((int) $text);

                return $var;
            }
            if (\str_contains($xsiType, 'boolean')) {
                $var->bool('true' === \strtolower($text) || '1' === $text);

                return $var;
            }
            if (\str_contains($xsiType, 'float') || \str_contains($xsiType, 'double')) {
                $var->float((float) $text);

                return $var;
            }
            $var->string($text);

            return $var;
        }
        // Nested: flatten first child text for v1 named params (document/literal style).
        if (1 === \count($childElements)) {
            return self::domToVariable($childElements[0]);
        }
        $ht = new HashTable();
        $i = 0;
        foreach ($childElements as $child) {
            $ht->addIndex($i, self::domToVariable($child));
            ++$i;
        }
        $var->array($ht);

        return $var;
    }

    /**
     * @param list<Variable> $args
     */
    private static function dispatch(
        ObjectEntry $object,
        string $opName,
        array $args,
        Context $ctx,
        Frame $frame
    ): Variable {
        $state = self::state($object);
        $lc = \strtolower($opName);

        // php-src WSDL mode: only SDL operations are valid (#20314).
        if (
            null !== $state->wsdl
            && '' !== $state->wsdl
            && $state->wsdlOperationIndex !== []
            && !isset($state->wsdlOperationIndex[$lc])
        ) {
            throw new \SoapFault('Client', 'Function "'.$opName.'" doesn\'t exist');
        }

        if (null !== $state->object) {
            return $ctx->runtime->vm->invokeInstanceMethod($state->object, $opName, ...$args);
        }

        if (null !== $state->className && '' !== $state->className) {
            $classLc = \strtolower($state->className);
            if (!isset($ctx->classes[$classLc])) {
                throw new \SoapFault('Server', 'SoapServer::setClass(): class "'.$state->className.'" does not exist');
            }
            $instance = self::resolveSetClassInstance($state, $ctx, $classLc);

            return $ctx->runtime->vm->invokeInstanceMethod($instance, $opName, ...$args);
        }

        if ($state->functionsAll || isset($state->functionIndex[$lc])) {
            $fnName = $state->functionsAll ? $opName : $state->functionIndex[$lc];
            if ($state->functionsAll) {
                $resolvedLc = $ctx->resolveFunctionCallLc($fnName);
                if (null === $resolvedLc) {
                    throw new \SoapFault('Client', 'Function "'.$opName.'" doesn\'t exist');
                }
                $handler = $ctx->functions[$resolvedLc];
                if ($handler instanceof PhpFunc) {
                    // Isolated stack: outer user try/catch around handle() must not absorb
                    // SoapFault from $server->fault() so handle() can emit Fault XML (#20194).
                    return $ctx->runtime->vm->invokePhpFunctionIsolated($handler, ...$args);
                }
                if ($handler instanceof InternalFunc) {
                    return VmInternalCall::invokeInContext($ctx, $handler, ...$args);
                }

                throw new \SoapFault('Client', 'Function "'.$opName.'" doesn\'t exist');
            }
            // Isolated stack: outer user try/catch around handle() must not absorb
            // SoapFault from $server->fault() so handle() can emit Fault XML (#20194).
            $fn = VmUserCall::resolveStringCallback($ctx, $fnName);

            return $ctx->runtime->vm->invokePhpFunctionIsolated($fn, ...$args);
        }

        throw new \SoapFault('Client', 'Function "'.$opName.'" doesn\'t exist');
    }

    /**
     * Resolve setClass instance — REQUEST always fresh; SESSION via $_SESSION['_bogus_session_name'] (#20315).
     */
    private static function resolveSetClassInstance(
        SoapServerState $state,
        Context $ctx,
        string $classLc
    ): ObjectEntry {
        if (SoapConstants::SOAP_PERSISTENCE_SESSION === $state->persistence) {
            if (null !== $state->classInstance) {
                return $state->classInstance;
            }
            $fromSession = self::loadSessionClassInstance($ctx, $classLc);
            if (null !== $fromSession) {
                $state->classInstance = $fromSession;

                return $fromSession;
            }
        }

        // php-src: instantiate with setClass ctor argv (#20294).
        $ce = $ctx->classes[$classLc];
        $instance = new ObjectEntry($ce);
        $ctorArgs = [];
        foreach ($state->classCtorArgs as $arg) {
            $copy = new Variable();
            $copy->copyFrom($arg);
            $ctorArgs[] = $copy;
        }
        if (null !== $ce->constructor || isset($ce->methods['__construct'])) {
            $ctx->runtime->vm->invokeInstanceMethod($instance, '__construct', ...$ctorArgs);
        }
        $instance->constructed = true;

        if (SoapConstants::SOAP_PERSISTENCE_SESSION === $state->persistence) {
            $state->classInstance = $instance;
            self::storeSessionClassInstance($ctx, $instance);
        }

        return $instance;
    }

    private static function loadSessionClassInstance(Context $ctx, string $classLc): ?ObjectEntry
    {
        if (!VmSession::isActive()) {
            // php-src: auto session_start when persistence is SESSION and session not disabled.
            VmSession::start($ctx);
        }
        $sessionVar = $ctx->getSuperglobal('_SESSION');
        if (null === $sessionVar || Variable::TYPE_ARRAY !== $sessionVar->type) {
            return null;
        }
        $stored = $sessionVar->toArray()->find(self::SESSION_OBJECT_KEY);
        if (null === $stored) {
            return null;
        }
        $stored = $stored->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $stored->type) {
            return null;
        }
        $obj = $stored->toObject();
        if (\strtolower($obj->class->name) !== $classLc) {
            // php-src incomplete_class fault — here mismatched class is ignored and recreated.
            return null;
        }

        return $obj;
    }

    private static function storeSessionClassInstance(Context $ctx, ObjectEntry $instance): void
    {
        if (!VmSession::isActive()) {
            VmSession::start($ctx);
        }
        $sessionVar = $ctx->ensureSuperglobal('_SESSION');
        if (Variable::TYPE_ARRAY !== $sessionVar->type) {
            $sessionVar->array(new HashTable());
        }
        $box = new Variable();
        $box->object($instance);
        $sessionVar->toArray()->add(self::SESSION_OBJECT_KEY, $box);
    }

    private static function buildResponse(SoapServerState $state, string $opName, Variable $result): string
    {
        $ns = $state->uri !== '' ? $state->uri : 'http://example.com/';
        if (SoapConstants::SOAP_1_2 === $state->soapVersion) {
            $envelopeNs = 'http://www.w3.org/2003/05/soap-envelope';
            $prefix = 'env';
            $encNs = SoapConstants::SOAP_1_2_ENC_NAMESPACE;
            $encPrefix = 'enc';
        } else {
            $envelopeNs = 'http://schemas.xmlsoap.org/soap/envelope/';
            $prefix = 'SOAP-ENV';
            $encNs = SoapConstants::SOAP_1_1_ENC_NAMESPACE;
            $encPrefix = 'SOAP-ENC';
        }
        $respName = $opName.'Response';
        $inner = self::encodeReturn($result);

        $headerXml = '';
        if ($state->responseHeaders !== []) {
            $headerXml = '  <'.$prefix.':Header>'."\n";
            foreach ($state->responseHeaders as $hdr) {
                $headerXml .= self::encodeSoapHeaderElement($hdr, $prefix, $state->soapVersion);
            }
            $headerXml .= '  </'.$prefix.':Header>'."\n";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".
            '<'.$prefix.':Envelope xmlns:'.$prefix.'="'.$envelopeNs.'"'.
            ' xmlns:ns1="'.\htmlspecialchars($ns, \ENT_XML1).'"'.
            ' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'.
            ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'.
            ' xmlns:'.$encPrefix.'="'.$encNs.'"'.
            ' '.$prefix.':encodingStyle="'.$encNs.'">'."\n".
            $headerXml.
            '  <'.$prefix.':Body>'."\n".
            '    <ns1:'.$respName.'>'.$inner.'</ns1:'.$respName.'>'."\n".
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
                if (Variable::TYPE_STRING === $dataVar->type) {
                    $inner = \htmlspecialchars($dataVar->toString(), \ENT_XML1);
                } elseif (Variable::TYPE_INTEGER === $dataVar->type) {
                    $inner = (string) $dataVar->toInt();
                } elseif (Variable::TYPE_BOOLEAN === $dataVar->type) {
                    $inner = $dataVar->toBool() ? 'true' : 'false';
                } else {
                    $inner = \htmlspecialchars($dataVar->toString(), \ENT_XML1);
                }
            }
        }

        return '    <'.$tag.$attrs.'>'.$inner.'</'.$tag.'>'."\n";
    }

    private static function encodeReturn(Variable $result): string
    {
        $result = $result->resolveIndirect();
        if (Variable::TYPE_NULL === $result->type) {
            return '<return xsi:nil="true"/>';
        }
        if (Variable::TYPE_BOOLEAN === $result->type) {
            return '<return xsi:type="xsd:boolean">'.($result->toBool() ? 'true' : 'false').'</return>';
        }
        if (Variable::TYPE_INTEGER === $result->type) {
            return '<return xsi:type="xsd:int">'.$result->toInt().'</return>';
        }
        if (Variable::TYPE_FLOAT === $result->type) {
            return '<return xsi:type="xsd:float">'.$result->toFloat().'</return>';
        }
        if (Variable::TYPE_STRING === $result->type) {
            return '<return xsi:type="xsd:string">'.\htmlspecialchars($result->toString(), \ENT_XML1).'</return>';
        }
        if (Variable::TYPE_ARRAY === $result->type) {
            $inner = '';
            foreach ($result->toArray()->iterateKeyed(false) as $pair) {
                $key = $pair[0];
                $tag = \is_int($key)
                    ? 'item'
                    : (\preg_replace('/[^A-Za-z0-9_.-]/', '_', (string) $key) ?: 'item');
                $val = $pair[1]->resolveIndirect();
                if (Variable::TYPE_STRING === $val->type) {
                    $inner .= '<'.$tag.' xsi:type="xsd:string">'.\htmlspecialchars($val->toString(), \ENT_XML1).'</'.$tag.'>';
                } elseif (Variable::TYPE_INTEGER === $val->type) {
                    $inner .= '<'.$tag.' xsi:type="xsd:int">'.$val->toInt().'</'.$tag.'>';
                } elseif (Variable::TYPE_BOOLEAN === $val->type) {
                    $inner .= '<'.$tag.' xsi:type="xsd:boolean">'.($val->toBool() ? 'true' : 'false').'</'.$tag.'>';
                } else {
                    $inner .= '<'.$tag.' xsi:type="xsd:string">'.\htmlspecialchars((string) $val->toString(), \ENT_XML1).'</'.$tag.'>';
                }
            }

            return '' !== $inner ? $inner : '<return/>';
        }

        return '<return xsi:type="xsd:string">'.\htmlspecialchars((string) $result->toString(), \ENT_XML1).'</return>';
    }

    private static function buildFaultResponse(SoapServerState $state, \SoapFault $e): string
    {
        $code = (string) ($e->faultcode ?? 'Server');
        $string = (string) ($e->faultstring !== '' ? $e->faultstring : $e->getMessage());
        $actor = isset($e->faultactor) ? (string) $e->faultactor : '';
        $details = $e->detail ?? null;
        $name = isset($e->_name) ? (string) $e->_name : '';
        $lang = isset($e->lang) ? (string) $e->lang : '';
        $faultcodens = isset($e->faultcodens) ? (string) $e->faultcodens : '';
        $headerFault = self::headerFaultFromException($e);

        return self::buildFaultEnvelope(
            $state->soapVersion,
            $code,
            $string,
            $actor,
            $details,
            $name,
            $lang,
            $faultcodens,
            $headerFault
        );
    }

    private static function buildFaultFromPending(SoapServerState $state): string
    {
        $pending = $state->pendingFault;
        if (null === $pending) {
            return self::buildFaultEnvelope($state->soapVersion, 'Server', 'Unknown');
        }

        return self::buildFaultEnvelope(
            $state->soapVersion,
            $pending['code'],
            $pending['string'],
            $pending['actor'] ?? '',
            $pending['details'] ?? null,
            $pending['name'] ?? '',
            $pending['lang'] ?? ''
        );
    }

    private static function buildFaultEnvelope(
        int $soapVersion,
        string $code,
        string $string,
        string $actor = '',
        mixed $details = null,
        string $name = '',
        string $lang = '',
        string $faultcodens = '',
        mixed $headerFault = null
    ): string {
        if (SoapConstants::SOAP_1_2 === $soapVersion) {
            return self::buildSoap12FaultEnvelope(
                $code,
                $string,
                $actor,
                $details,
                $name,
                $lang,
                $faultcodens,
                $headerFault
            );
        }

        $envNs = SoapConstants::SOAP_1_1_ENV_NAMESPACE;
        $envPfx = SoapConstants::SOAP_1_1_ENV_NS_PREFIX;
        [$codeXml, $extraNs] = self::faultCodeXmlQName($code, $faultcodens, $envPfx, $envNs);
        $body = '      <faultcode>'.$codeXml.'</faultcode>'."\n".
            '      <faultstring>'.\htmlspecialchars($string, \ENT_XML1).'</faultstring>'."\n";
        if ('' !== $actor) {
            $body .= '      <faultactor>'.\htmlspecialchars($actor, \ENT_XML1).'</faultactor>'."\n";
        }
        if (null !== $details) {
            $detailInner = self::encodeFaultDetail($details, $name);
            $body .= '      <detail>'.$detailInner.'</detail>'."\n";
        }

        $headerXml = self::encodeFaultHeaderXml($headerFault, $envPfx, $soapVersion);

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".
            '<'.$envPfx.':Envelope xmlns:'.$envPfx.'="'.$envNs.'"'.$extraNs.'>'."\n".
            $headerXml.
            '  <'.$envPfx.':Body>'."\n".
            '    <'.$envPfx.':Fault>'."\n".
            $body.
            '    </'.$envPfx.':Fault>'."\n".
            '  </'.$envPfx.':Body>'."\n".
            '</'.$envPfx.':Envelope>';
    }

    /**
     * SOAP 1.2 Fault envelope (php-src serialize_response_call SOAP_1_2 branch; #20221 / #31944).
     *
     * env:Code/Value is an env QName only when php-src set_soap_fault sets faultcodens
     * (Client→Sender, Server→Receiver, VersionMismatch, MustUnderstand, DataEncodingUnknown).
     * Passing already-mapped "Receiver"/"Sender" is a custom code (unprefixed), matching Zend 8.2.
     * Array ctor ($ns, $code) sets faultcodens and QNames via encode_add_ns (#31956).
     */
    private static function buildSoap12FaultEnvelope(
        string $code,
        string $string,
        string $actor = '',
        mixed $details = null,
        string $name = '',
        string $lang = '',
        string $faultcodens = '',
        mixed $headerFault = null
    ): string {
        $ns = SoapConstants::SOAP_1_2_ENV_NAMESPACE;
        $pfx = SoapConstants::SOAP_1_2_ENV_NS_PREFIX;
        [$value, $extraNs] = self::soap12FaultCodeValue($code, $pfx, $faultcodens);
        // php-src SoapServer::fault default $lang is empty → no xml:lang (Zend 8.2).
        // Non-empty $lang sets xml:lang on env:Text (php-src xmlNodeSetLang).
        $langAttr = '' !== $lang ? ' xml:lang="'.\htmlspecialchars($lang, \ENT_XML1).'"' : '';
        $body = '      <'.$pfx.':Code><'.$pfx.':Value>'.$value.'</'.$pfx.':Value></'.$pfx.':Code>'."\n".
            '      <'.$pfx.':Reason><'.$pfx.':Text'.$langAttr.'>'.
            \htmlspecialchars($string, \ENT_XML1).
            '</'.$pfx.':Text></'.$pfx.':Reason>'."\n";
        // php-src serialize_response_call SOAP_1_2: Z_FAULT_ACTOR_P is not
        // serialized (no env:Node / env:Role). $actor stays on SoapFault only.
        // Detail element is SOAP_1_2_ENV_NS_PREFIX ":Detail" (#31945).
        if (null !== $details) {
            $detailInner = self::encodeFaultDetail($details, $name);
            $body .= '      <'.$pfx.':Detail>'.$detailInner.'</'.$pfx.':Detail>'."\n";
        }

        $headerXml = self::encodeFaultHeaderXml($headerFault, $pfx, SoapConstants::SOAP_1_2);

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".
            '<'.$pfx.':Envelope xmlns:'.$pfx.'="'.$ns.'"'.$extraNs.'>'."\n".
            $headerXml.
            '  <'.$pfx.':Body>'."\n".
            '    <'.$pfx.':Fault>'."\n".
            $body.
            '    </'.$pfx.':Fault>'."\n".
            '  </'.$pfx.':Body>'."\n".
            '</'.$pfx.':Envelope>';
    }

    /**
     * php-src serialize_response_call: Z_FAULT_HEADERFAULT_P → env:Header before Body (#31958).
     */
    private static function encodeFaultHeaderXml(mixed $headerFault, string $prefix, int $soapVersion): string
    {
        if (null === $headerFault) {
            return '';
        }
        if ($headerFault instanceof ObjectEntry) {
            return '  <'.$prefix.':Header>'."\n".
                self::encodeSoapHeaderElement($headerFault, $prefix, $soapVersion).
                '  </'.$prefix.':Header>'."\n";
        }
        if ($headerFault instanceof \SoapHeader) {
            return '  <'.$prefix.':Header>'."\n".
                self::encodeNativeSoapHeaderElement($headerFault, $prefix, $soapVersion).
                '  </'.$prefix.':Header>'."\n";
        }

        return '';
    }

    private static function encodeNativeSoapHeaderElement(\SoapHeader $header, string $prefix, int $soapVersion): string
    {
        $ns = isset($header->namespace) ? (string) $header->namespace : '';
        $name = isset($header->name) ? (string) $header->name : 'Header';
        $tag = \preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) ?: 'Header';
        $attrs = '';
        if ('' !== $ns) {
            $attrs .= ' xmlns="'.\htmlspecialchars($ns, \ENT_XML1).'"';
        }
        $inner = '';
        if (isset($header->data) && null !== $header->data) {
            $data = $header->data;
            if (\is_string($data)) {
                $inner = \htmlspecialchars($data, \ENT_XML1);
            } elseif (\is_int($data) || \is_float($data)) {
                $inner = (string) $data;
            } elseif (\is_bool($data)) {
                $inner = $data ? 'true' : 'false';
            } else {
                $inner = \htmlspecialchars((string) $data, \ENT_XML1);
            }
        }

        return '    <'.$tag.$attrs.'>'.$inner.'</'.$tag.'>'."\n";
    }

    /**
     * Read SoapFault::$headerfault (VM ObjectEntry or native SoapHeader after rematerialize).
     */
    private static function headerFaultFromException(\SoapFault $e): mixed
    {
        if (!isset($e->headerfault)) {
            return null;
        }
        $hf = $e->headerfault;
        if (null === $hf) {
            return null;
        }
        if ($hf instanceof ObjectEntry || $hf instanceof \SoapHeader) {
            return $hf;
        }

        return null;
    }

    /**
     * php-src set_soap_fault SOAP_1_2 + serialize_response_call Code/Value (#31944 / #31956).
     *
     * @return array{0: string, 1: string} [Value text, extra Envelope xmlns attr]
     */
    private static function soap12FaultCodeValue(string $code, string $pfx, string $faultcodens = ''): array
    {
        if ('' !== $faultcodens) {
            return self::faultCodeXmlQName(
                $code,
                $faultcodens,
                $pfx,
                SoapConstants::SOAP_1_2_ENV_NAMESPACE
            );
        }
        $qname = false;
        if ('Client' === $code) {
            $code = 'Sender';
            $qname = true;
        } elseif ('Server' === $code) {
            $code = 'Receiver';
            $qname = true;
        } elseif (
            'VersionMismatch' === $code
            || 'MustUnderstand' === $code
            || 'DataEncodingUnknown' === $code
        ) {
            $qname = true;
        }
        $escaped = \htmlspecialchars($code, \ENT_XML1);

        return [$qname ? $pfx.':'.$escaped : $escaped, ''];
    }

    /**
     * php-src serialize_response_call: encode_add_ns + xmlBuildQName when Z_FAULT_CODENS_P set (#31956).
     *
     * Custom namespaces get ns1 (SOAP_GLOBAL(cur_uniq_ns) first unused prefix). Envelope
     * env/SOAP-ENV href reuses the envelope prefix — no extra xmlns.
     *
     * @return array{0: string, 1: string} [QName or local, extra Envelope xmlns attr]
     */
    private static function faultCodeXmlQName(
        string $code,
        string $faultcodens,
        string $envelopePfx,
        string $envelopeNs
    ): array {
        $escaped = \htmlspecialchars($code, \ENT_XML1);
        if ('' === $faultcodens) {
            return [$escaped, ''];
        }
        if ($faultcodens === $envelopeNs) {
            return [$envelopePfx.':'.$escaped, ''];
        }
        if (SoapConstants::SOAP_1_2_ENV_NAMESPACE === $faultcodens) {
            return [
                SoapConstants::SOAP_1_2_ENV_NS_PREFIX.':'.$escaped,
                ' xmlns:'.SoapConstants::SOAP_1_2_ENV_NS_PREFIX.'="'.SoapConstants::SOAP_1_2_ENV_NAMESPACE.'"',
            ];
        }
        if (SoapConstants::SOAP_1_1_ENV_NAMESPACE === $faultcodens) {
            return [
                SoapConstants::SOAP_1_1_ENV_NS_PREFIX.':'.$escaped,
                ' xmlns:'.SoapConstants::SOAP_1_1_ENV_NS_PREFIX.'="'.SoapConstants::SOAP_1_1_ENV_NAMESPACE.'"',
            ];
        }
        $escapedNs = \htmlspecialchars($faultcodens, \ENT_XML1);

        return ['ns1:'.$escaped, ' xmlns:ns1="'.$escapedNs.'"'];
    }

    private static function encodeFaultDetail(mixed $details, string $name): string
    {
        $tag = '' !== $name
            ? (\preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) ?: 'detail')
            : null;
        if (\is_string($details) || \is_numeric($details) || \is_bool($details)) {
            $text = \htmlspecialchars((string) $details, \ENT_XML1);
            if (null !== $tag) {
                return '<'.$tag.'>'.$text.'</'.$tag.'>';
            }

            return $text;
        }
        if (null === $details) {
            return '';
        }
        // Non-scalar v1: stringify.
        $text = \htmlspecialchars((string) $details, \ENT_XML1);
        if (null !== $tag) {
            return '<'.$tag.'>'.$text.'</'.$tag.'>';
        }

        return $text;
    }

    private static function loadWsdlFunctions(SoapServerState $state, string $wsdl): void
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
        foreach ($xpath->query('//wsdl:portType/wsdl:operation') ?: [] as $op) {
            if (!$op instanceof \DOMElement) {
                continue;
            }
            $name = $op->getAttribute('name');
            if ('' !== $name) {
                $state->wsdlOperations[] = $name;
                $state->wsdlOperationIndex[\strtolower($name)] = $name;
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
    }
}

final class SoapServerState
{
    public ?string $wsdl = null;

    /** @var array<string, mixed> */
    public array $options = [];

    public string $uri = '';

    public int $soapVersion = SoapConstants::SOAP_1_1;

    /** @var list<string> */
    public array $functions = [];

    /** @var array<string, string> */
    public array $functionIndex = [];

    /** php-src soap_functions.functions_all after addFunction(SOAP_FUNCTIONS_ALL) (#20292). */
    public bool $functionsAll = false;

    /** @var list<string> */
    public array $wsdlOperations = [];

    /** @var array<string, string> lowercase op name → original (#20314). */
    public array $wsdlOperationIndex = [];

    public ?string $className = null;

    public ?ObjectEntry $object = null;

    /** Cached setClass instance when SOAP_PERSISTENCE_SESSION (in-process v1). */
    public ?ObjectEntry $classInstance = null;

    /** @var list<Variable> SoapServer::setClass() constructor argv (#20294). */
    public array $classCtorArgs = [];

    public int $persistence = SoapConstants::SOAP_PERSISTENCE_REQUEST;

    /** @var list<ObjectEntry> */
    public array $responseHeaders = [];

    public string $lastResponse = '';

    /**
     * In-handler SoapServer::fault() payload (php-src soap_server_fault_ex; #20194, #20219).
     *
     * @var array{code: string, string: string, actor?: string, details?: mixed, name?: string, lang?: string}|null
     */
    public ?array $pendingFault = null;
}

final class SoapServerConstruct extends SoapClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException('SoapServer::__construct() called without $this');
        }
        $receiver = $this->receiver($frame, 'SoapServer::__construct()');
        $wsdl = null;
        if (\array_key_exists(1, $frame->calledArgs)) {
            $w = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $w->type) {
                $wsdl = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'SoapServer::__construct', 1, 'wsdl');
            }
        }
        $options = [];
        if (\array_key_exists(2, $frame->calledArgs)) {
            $optVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_ARRAY === $optVar->type) {
                $exported = VmJson::export($optVar, $frame->vmContext, null, $frame);
                if (\is_array($exported)) {
                    $options = $exported;
                }
            }
        }
        // Non-WSDL requires uri option (php-src).
        if ((null === $wsdl || '' === $wsdl) && !isset($options['uri'])) {
            throw new \SoapFault('Server', 'SoapServer::SoapServer(): Invalid parameters');
        }
        VmSoapServer::initObject($receiver, $wsdl, $options);
    }
}

final class SoapServerAddFunction extends SoapClassMethod
{
    public function __construct()
    {
        parent::__construct('addFunction');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SoapServer::addFunction()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('SoapServer::addFunction() expects exactly 1 argument, 0 given');
        }
        $arg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING === $arg->type) {
            VmSoapServer::addFunction($receiver, $arg->toString());
        } elseif (Variable::TYPE_ARRAY === $arg->type) {
            $list = [];
            foreach ($arg->toArray()->iterateKeyed(false) as $pair) {
                $v = $pair[1]->resolveIndirect();
                $list[] = Variable::TYPE_STRING === $v->type ? $v->toString() : (string) $v->toString();
            }
            VmSoapServer::addFunction($receiver, $list);
        } elseif (Variable::TYPE_INTEGER === $arg->type) {
            // php-src IS_LONG: only SOAP_FUNCTIONS_ALL; else ValueError (#20292).
            if (SoapConstants::SOAP_FUNCTIONS_ALL !== $arg->toInt()) {
                throw new \ValueError(
                    'SoapServer::addFunction(): Argument #1 ($functions) must be SOAP_FUNCTIONS_ALL when an integer is passed'
                );
            }
            VmSoapServer::addFunctionAll($receiver, $frame);
        } else {
            throw new \TypeError('SoapServer::addFunction(): Argument #1 ($functions) must be of type array|string|int');
        }
    }
}

final class SoapServerSetClass extends SoapClassMethod
{
    public function __construct()
    {
        parent::__construct('setClass');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SoapServer::setClass()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('SoapServer::setClass() expects at least 1 argument, 0 given');
        }
        $className = $this->stringArg($frame->calledArgs[1], 'SoapServer::setClass', 0, 'class_name');
        $ctorArgs = [];
        $argc = \count($frame->calledArgs);
        for ($i = 2; $i < $argc; ++$i) {
            $copy = new Variable();
            $copy->copyFrom($frame->calledArgs[$i]->resolveIndirect());
            $ctorArgs[] = $copy;
        }
        VmSoapServer::setClass($receiver, $className, $ctorArgs);
    }
}

final class SoapServerSetObject extends SoapClassMethod
{
    public function __construct()
    {
        parent::__construct('setObject');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SoapServer::setObject()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('SoapServer::setObject() expects exactly 1 argument, 0 given');
        }
        $objVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $objVar->type) {
            throw new \TypeError('SoapServer::setObject(): Argument #1 ($object) must be of type object');
        }
        VmSoapServer::setObject($receiver, $objVar->toObject());
    }
}

final class SoapServerGetFunctions extends SoapClassMethod
{
    public function __construct()
    {
        parent::__construct('getFunctions');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SoapServer::getFunctions()');
        if (null === $frame->returnVar) {
            return;
        }
        $ht = new HashTable();
        $i = 0;
        $ctx = $frame->vmContext;
        foreach (VmSoapServer::getFunctions($receiver, $ctx) as $fn) {
            $slot = new Variable();
            $slot->string($fn);
            $ht->addIndex($i, $slot);
            ++$i;
        }
        $frame->returnVar->array($ht);
    }
}

final class SoapServerHandle extends SoapClassMethod
{
    public function __construct()
    {
        parent::__construct('handle');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SoapServer::handle()');
        $request = null;
        if (\array_key_exists(1, $frame->calledArgs)) {
            $r = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $r->type) {
                $request = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'SoapServer::handle', 1, 'request');
            }
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('SoapServer::handle() requires VM context');
        }
        VmSoapServer::handle($receiver, $request, $frame->vmContext, $frame);
    }
}

final class SoapServerFault extends SoapClassMethod
{
    public function __construct()
    {
        parent::__construct('fault');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SoapServer::fault()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError('SoapServer::fault() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given');
        }
        $code = $this->stringArg($frame->calledArgs[1], 'SoapServer::fault', 0, 'code');
        $string = $this->stringArg($frame->calledArgs[2], 'SoapServer::fault', 1, 'string');
        $actor = '';
        if (\array_key_exists(3, $frame->calledArgs)) {
            $a = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $a->type) {
                $actor = VmString::coerceStringBuiltinArg($frame->calledArgs[3], 'SoapServer::fault', 3, 'actor');
            }
        }
        $details = null;
        if (\array_key_exists(4, $frame->calledArgs)) {
            $d = $frame->calledArgs[4]->resolveIndirect();
            if (Variable::TYPE_NULL === $d->type) {
                $details = null;
            } elseif (Variable::TYPE_STRING === $d->type) {
                $details = $d->toString();
            } elseif (Variable::TYPE_INTEGER === $d->type) {
                $details = $d->toInt();
            } elseif (Variable::TYPE_BOOLEAN === $d->type) {
                $details = $d->toBool();
            } elseif (Variable::TYPE_FLOAT === $d->type) {
                $details = $d->toFloat();
            } else {
                $details = $d->toString();
            }
        }
        $name = '';
        if (\array_key_exists(5, $frame->calledArgs)) {
            $n = $frame->calledArgs[5]->resolveIndirect();
            if (Variable::TYPE_NULL !== $n->type) {
                $name = VmString::coerceStringBuiltinArg($frame->calledArgs[5], 'SoapServer::fault', 5, 'name');
            }
        }
        $lang = '';
        if (\array_key_exists(6, $frame->calledArgs)) {
            $l = $frame->calledArgs[6]->resolveIndirect();
            if (Variable::TYPE_NULL !== $l->type) {
                $lang = VmString::coerceStringBuiltinArg($frame->calledArgs[6], 'SoapServer::fault', 6, 'lang');
            }
        }
        VmSoapServer::fault($receiver, $code, $string, $actor, $details, $name, $lang);
    }
}

final class SoapServerAddSoapHeader extends SoapClassMethod
{
    public function __construct()
    {
        parent::__construct('addSoapHeader');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                'SoapServer::addSoapHeader() expects exactly 1 argument, '.($argc - 1).' given'
            );
        }
        $receiver = $this->receiver($frame, 'SoapServer::addSoapHeader()');
        $hdrVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $hdrVar->type) {
            throw new \TypeError('SoapServer::addSoapHeader(): Argument #1 ($header) must be of type SoapHeader');
        }
        VmSoapServer::addSoapHeader($receiver, $hdrVar->toObject());
    }
}

final class SoapServerSetPersistence extends SoapClassMethod
{
    public function __construct()
    {
        parent::__construct('setPersistence');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                'SoapServer::setPersistence() expects exactly 1 argument, '.($argc - 1).' given'
            );
        }
        $receiver = $this->receiver($frame, 'SoapServer::setPersistence()');
        $mode = (int) $frame->calledArgs[1]->resolveIndirect()->toInt();
        VmSoapServer::setPersistence($receiver, $mode);
    }
}

final class SoapServerGetLastResponse extends SoapClassMethod
{
    public function __construct()
    {
        parent::__construct('__getLastResponse');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'SoapServer::__getLastResponse()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmSoapServer::state($receiver)->lastResponse);
    }
}
