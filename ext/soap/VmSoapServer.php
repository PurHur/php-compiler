<?php

declare(strict_types=1);

namespace PHPCompiler\ext\soap;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\OutputBuffer;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\ext\standard\VmUserCall;
use PHPCompiler\VM\MagicMethodInvocationAborted;
use PHPCompiler\VM\ScriptExit;

/**
 * SoapServer VM class — v1 string handle + addFunction/setObject (php-src ext/soap/soap.c; #20126).
 */
final class VmSoapServer
{
    public const CLASS_LC = 'soapserver';

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
        foreach ($functions as $fn) {
            $name = (string) $fn;
            if ('' === $name) {
                continue;
            }
            $state->functions[] = $name;
            $state->functionIndex[\strtolower($name)] = $name;
        }
    }

    public static function setClass(ObjectEntry $object, string $className): void
    {
        $state = self::state($object);
        $state->className = $className;
        $state->object = null;
        $state->classInstance = null;
    }

    public static function setObject(ObjectEntry $object, ObjectEntry $service): void
    {
        $state = self::state($object);
        $state->object = $service;
        $state->className = null;
        $state->classInstance = null;
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
    public static function getFunctions(ObjectEntry $object): array
    {
        return self::state($object)->functions;
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
            try {
                [$opName, $args] = self::parseRequest($request);
                $result = self::dispatch($object, $opName, $args, $ctx, $frame);
                if (null !== $state->pendingFault) {
                    $response = self::buildFaultFromPending($state);
                } else {
                    $response = self::buildResponse($state, $opName, $result);
                }
            } catch (\SoapFault $e) {
                // Prefer pendingFault: isolated dispatch rematerializes SoapFault as
                // new SoapFault($message) and drops faultcode (#20194).
                $response = null !== $state->pendingFault
                    ? self::buildFaultFromPending($state)
                    : self::buildFaultResponse($state, $e);
            } catch (MagicMethodInvocationAborted $e) {
                if (null === $state->pendingFault) {
                    throw $e;
                }
                $response = self::buildFaultFromPending($state);
            } catch (ScriptExit $e) {
                throw $e;
            } catch (\Throwable $e) {
                if (null !== $state->pendingFault) {
                    $response = self::buildFaultFromPending($state);
                } else {
                    $sf = new \SoapFault('Server', $e->getMessage());
                    $response = self::buildFaultResponse($state, $sf);
                }
            }

            $state->lastResponse = $response;
            OutputBuffer::append($response);
        } finally {
            --self::$handleDepth;
            $state->pendingFault = null;
        }
    }

    public static function fault(ObjectEntry $object, string $code, string $string): void
    {
        $state = self::state($object);
        // Zend soap_server_fault_ex: in-handler fault is serialized into the response
        // buffer; outside handle() the SoapFault escapes to user code (#20194).
        if (self::$handleDepth > 0) {
            $state->pendingFault = ['code' => $code, 'string' => $string];
        }
        throw new \SoapFault($code, $string);
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

        if (null !== $state->object) {
            return $ctx->runtime->vm->invokeInstanceMethod($state->object, $opName, ...$args);
        }

        if (null !== $state->className && '' !== $state->className) {
            $classLc = \strtolower($state->className);
            if (!isset($ctx->classes[$classLc])) {
                throw new \SoapFault('Server', 'SoapServer::setClass(): class "'.$state->className.'" does not exist');
            }
            $instance = $state->classInstance;
            if (
                null === $instance
                || SoapConstants::SOAP_PERSISTENCE_SESSION !== $state->persistence
            ) {
                $instance = new ObjectEntry($ctx->classes[$classLc]);
                $instance->constructed = true;
                if (SoapConstants::SOAP_PERSISTENCE_SESSION === $state->persistence) {
                    $state->classInstance = $instance;
                }
            }

            return $ctx->runtime->vm->invokeInstanceMethod($instance, $opName, ...$args);
        }

        if (isset($state->functionIndex[$lc])) {
            $fnName = $state->functionIndex[$lc];
            // Isolated stack: outer user try/catch around handle() must not absorb
            // SoapFault from $server->fault() so handle() can emit Fault XML (#20194).
            $fn = VmUserCall::resolveStringCallback($ctx, $fnName);

            return $ctx->runtime->vm->invokePhpFunctionIsolated($fn, ...$args);
        }

        throw new \SoapFault('Client', 'Function "'.$opName.'" doesn\'t exist');
    }

    private static function buildResponse(SoapServerState $state, string $opName, Variable $result): string
    {
        $ns = $state->uri !== '' ? $state->uri : 'http://example.com/';
        $envelopeNs = 'http://schemas.xmlsoap.org/soap/envelope/';
        $prefix = 'SOAP-ENV';
        $respName = $opName.'Response';
        $inner = self::encodeReturn($result);

        $headerXml = '';
        if ($state->responseHeaders !== []) {
            $headerXml = '  <'.$prefix.':Header>'."\n";
            foreach ($state->responseHeaders as $hdr) {
                $headerXml .= self::encodeSoapHeaderElement($hdr, $prefix);
            }
            $headerXml .= '  </'.$prefix.':Header>'."\n";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".
            '<'.$prefix.':Envelope xmlns:'.$prefix.'="'.$envelopeNs.'"'.
            ' xmlns:ns1="'.\htmlspecialchars($ns, \ENT_XML1).'"'.
            ' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'.
            ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'.
            ' xmlns:SOAP-ENC="http://schemas.xmlsoap.org/soap/encoding/"'.
            ' SOAP-ENV:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'."\n".
            $headerXml.
            '  <'.$prefix.':Body>'."\n".
            '    <ns1:'.$respName.'>'.$inner.'</ns1:'.$respName.'>'."\n".
            '  </'.$prefix.':Body>'."\n".
            '</'.$prefix.':Envelope>';
    }

    private static function encodeSoapHeaderElement(ObjectEntry $header, string $prefix): string
    {
        $ns = $header->hasProperty('namespace')
            ? $header->getProperty('namespace')->resolveIndirect()->toString()
            : '';
        $name = $header->hasProperty('name')
            ? $header->getProperty('name')->resolveIndirect()->toString()
            : 'Header';
        $tag = \preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) ?: 'Header';
        $must = false;
        if ($header->hasProperty('mustUnderstand')) {
            $mu = $header->getProperty('mustUnderstand')->resolveIndirect();
            if (Variable::TYPE_BOOLEAN === $mu->type) {
                $must = $mu->toBool();
            } elseif (Variable::TYPE_INTEGER === $mu->type) {
                $must = 0 !== $mu->toInt();
            }
        }
        $attrs = '';
        if ('' !== $ns) {
            $attrs .= ' xmlns="'.\htmlspecialchars($ns, \ENT_XML1).'"';
        }
        if ($must) {
            $attrs .= ' '.$prefix.':mustUnderstand="1"';
        }
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

        return self::buildFaultEnvelope($code, $string);
    }

    private static function buildFaultFromPending(SoapServerState $state): string
    {
        $pending = $state->pendingFault;
        if (null === $pending) {
            return self::buildFaultEnvelope('Server', 'Unknown');
        }

        return self::buildFaultEnvelope($pending['code'], $pending['string']);
    }

    private static function buildFaultEnvelope(string $code, string $string): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".
            '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'."\n".
            '  <SOAP-ENV:Body>'."\n".
            '    <SOAP-ENV:Fault>'."\n".
            '      <faultcode>'.\htmlspecialchars($code, \ENT_XML1).'</faultcode>'."\n".
            '      <faultstring>'.\htmlspecialchars($string, \ENT_XML1).'</faultstring>'."\n".
            '    </SOAP-ENV:Fault>'."\n".
            '  </SOAP-ENV:Body>'."\n".
            '</SOAP-ENV:Envelope>';
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

    /** @var list<string> */
    public array $wsdlOperations = [];

    public ?string $className = null;

    public ?ObjectEntry $object = null;

    /** Cached setClass instance when SOAP_PERSISTENCE_SESSION (in-process v1). */
    public ?ObjectEntry $classInstance = null;

    public int $persistence = SoapConstants::SOAP_PERSISTENCE_REQUEST;

    /** @var list<ObjectEntry> */
    public array $responseHeaders = [];

    public string $lastResponse = '';

    /**
     * In-handler SoapServer::fault() payload (php-src soap_server_fault_ex; #20194).
     *
     * @var array{code: string, string: string}|null
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
        VmSoapServer::setClass($receiver, $className);
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
        foreach (VmSoapServer::getFunctions($receiver) as $fn) {
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
        VmSoapServer::fault($receiver, $code, $string);
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
