<?php

declare(strict_types=1);

namespace PHPCompiler\ext\soap;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;

/**
 * SoapVar / SoapParam / SoapHeader — encoding helpers (php-src ext/soap/soap.c; #20125).
 */
final class VmSoapEncoding
{
    public static function register(Context $ctx): void
    {
        self::registerSoapVar($ctx);
        self::registerSoapParam($ctx);
        self::registerSoapHeader($ctx);
    }

    private static function registerSoapVar(Context $ctx): void
    {
        if (isset($ctx->classes['soapvar']) && isset($ctx->classes['soapvar']->methods['__construct'])) {
            return;
        }
        $entry = $ctx->classes['soapvar'] ?? new ClassEntry('SoapVar');
        $entry->isInternal = true;
        $pub = CfgFunc::FLAG_PUBLIC;
        $strProto = new Variable(Variable::TYPE_STRING);
        $intProto = new Variable(Variable::TYPE_INTEGER);
        $nullProto = new Variable(Variable::TYPE_NULL);

        $entry->properties = [
            new ClassProperty('enc_type', null, $intProto, false, $pub, 'soapvar'),
            new ClassProperty('enc_value', null, $nullProto, false, $pub, 'soapvar'),
            new ClassProperty('enc_stype', null, $nullProto, false, $pub, 'soapvar'),
            new ClassProperty('enc_ns', null, $nullProto, false, $pub, 'soapvar'),
            new ClassProperty('enc_name', null, $nullProto, false, $pub, 'soapvar'),
            new ClassProperty('enc_namens', null, $nullProto, false, $pub, 'soapvar'),
        ];
        $ctor = new SoapVarConstruct();
        $entry->constructor = $ctor;
        $entry->methods['__construct'] = $ctor;
        $entry->methodVisibility['__construct'] = $pub;
        $ctx->classes['soapvar'] = $entry;
    }

    private static function registerSoapParam(Context $ctx): void
    {
        if (isset($ctx->classes['soapparam']) && isset($ctx->classes['soapparam']->methods['__construct'])) {
            return;
        }
        $entry = $ctx->classes['soapparam'] ?? new ClassEntry('SoapParam');
        $entry->isInternal = true;
        $pub = CfgFunc::FLAG_PUBLIC;
        $strProto = new Variable(Variable::TYPE_STRING);
        $nullProto = new Variable(Variable::TYPE_NULL);
        $entry->properties = [
            new ClassProperty('param_name', null, $strProto, false, $pub, 'soapparam'),
            new ClassProperty('param_data', null, $nullProto, false, $pub, 'soapparam'),
        ];
        $ctor = new SoapParamConstruct();
        $entry->constructor = $ctor;
        $entry->methods['__construct'] = $ctor;
        $entry->methodVisibility['__construct'] = $pub;
        $ctx->classes['soapparam'] = $entry;
    }

    private static function registerSoapHeader(Context $ctx): void
    {
        if (isset($ctx->classes['soapheader']) && isset($ctx->classes['soapheader']->methods['__construct'])) {
            return;
        }
        $entry = $ctx->classes['soapheader'] ?? new ClassEntry('SoapHeader');
        $entry->isInternal = true;
        $pub = CfgFunc::FLAG_PUBLIC;
        $strProto = new Variable(Variable::TYPE_STRING);
        $boolProto = new Variable(Variable::TYPE_BOOLEAN);
        $nullProto = new Variable(Variable::TYPE_NULL);
        $false = new Variable(Variable::TYPE_BOOLEAN);
        $false->bool(false);
        $entry->properties = [
            new ClassProperty('namespace', null, $strProto, false, $pub, 'soapheader'),
            new ClassProperty('name', null, $strProto, false, $pub, 'soapheader'),
            new ClassProperty('data', null, $nullProto, false, $pub, 'soapheader'),
            new ClassProperty('mustUnderstand', $false, $boolProto, false, $pub, 'soapheader'),
            new ClassProperty('actor', null, $nullProto, false, $pub, 'soapheader'),
        ];
        $ctor = new SoapHeaderConstruct();
        $entry->constructor = $ctor;
        $entry->methods['__construct'] = $ctor;
        $entry->methodVisibility['__construct'] = $pub;
        $ctx->classes['soapheader'] = $entry;
    }

    public static function optionalStringArg(Frame $frame, int $index, string $label, string $param): ?string
    {
        if (!\array_key_exists($index, $frame->calledArgs)) {
            return null;
        }
        $var = $frame->calledArgs[$index]->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return VmString::coerceStringBuiltinArg($frame->calledArgs[$index], $label, $index, $param);
    }

    public static function optionalIntArg(Frame $frame, int $index): ?int
    {
        if (!\array_key_exists($index, $frame->calledArgs)) {
            return null;
        }
        $var = $frame->calledArgs[$index]->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            return $var->toBool() ? 1 : 0;
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            return (int) $var->toFloat();
        }
        if (Variable::TYPE_STRING === $var->type) {
            return (int) $var->toString();
        }

        return null;
    }

    public static function setNullableString(ObjectEntry $obj, string $prop, ?string $value): void
    {
        if (null === $value) {
            $obj->getProperty($prop)->null();
        } else {
            $obj->getProperty($prop)->string($value);
        }
    }
}

final class SoapVarConstruct extends SoapClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        // $this + data + encoding required; optional typeName/typeNamespace/nodeName/nodeNamespace
        if ($argc < 3) {
            throw new \ArgumentCountError(
                'SoapVar::__construct() expects at least 2 arguments, '.($argc - 1).' given'
            );
        }
        $receiver = $this->receiver($frame, 'SoapVar::__construct()');
        $data = $frame->calledArgs[1]->resolveIndirect();
        $encoding = VmSoapEncoding::optionalIntArg($frame, 2);
        $typeName = VmSoapEncoding::optionalStringArg($frame, 3, 'SoapVar::__construct', 'type_name');
        $typeNs = VmSoapEncoding::optionalStringArg($frame, 4, 'SoapVar::__construct', 'type_namespace');
        $nodeName = VmSoapEncoding::optionalStringArg($frame, 5, 'SoapVar::__construct', 'node_name');
        $nodeNs = VmSoapEncoding::optionalStringArg($frame, 6, 'SoapVar::__construct', 'node_namespace');

        $receiver->getProperty('enc_type')->int(null === $encoding ? 0 : $encoding);
        $receiver->getProperty('enc_value')->copyFrom($data);
        VmSoapEncoding::setNullableString($receiver, 'enc_stype', $typeName);
        VmSoapEncoding::setNullableString($receiver, 'enc_ns', $typeNs);
        VmSoapEncoding::setNullableString($receiver, 'enc_name', $nodeName);
        VmSoapEncoding::setNullableString($receiver, 'enc_namens', $nodeNs);
        $receiver->constructed = true;
    }
}

final class SoapParamConstruct extends SoapClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(
                'SoapParam::__construct() expects exactly 2 arguments, '.($argc - 1).' given'
            );
        }
        $receiver = $this->receiver($frame, 'SoapParam::__construct()');
        $data = $frame->calledArgs[1]->resolveIndirect();
        $name = $this->stringArg($frame->calledArgs[2], 'SoapParam::__construct', 1, 'name');
        $receiver->getProperty('param_name')->string($name);
        $receiver->getProperty('param_data')->copyFrom($data);
        $receiver->constructed = true;
    }
}

final class SoapHeaderConstruct extends SoapClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(
                'SoapHeader::__construct() expects at least 2 arguments, '.($argc - 1).' given'
            );
        }
        $receiver = $this->receiver($frame, 'SoapHeader::__construct()');
        $namespace = $this->stringArg($frame->calledArgs[1], 'SoapHeader::__construct', 0, 'namespace');
        $name = $this->stringArg($frame->calledArgs[2], 'SoapHeader::__construct', 1, 'name');
        $data = \array_key_exists(3, $frame->calledArgs)
            ? $frame->calledArgs[3]->resolveIndirect()
            : null;
        $mustUnderstand = false;
        if (\array_key_exists(4, $frame->calledArgs)) {
            $mu = $frame->calledArgs[4]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN === $mu->type) {
                $mustUnderstand = $mu->toBool();
            } elseif (Variable::TYPE_INTEGER === $mu->type) {
                $mustUnderstand = 0 !== $mu->toInt();
            }
        }
        $actor = null;
        if (\array_key_exists(5, $frame->calledArgs)) {
            $actorVar = $frame->calledArgs[5]->resolveIndirect();
            if (Variable::TYPE_NULL !== $actorVar->type) {
                if (Variable::TYPE_INTEGER === $actorVar->type) {
                    $receiver->getProperty('actor')->int($actorVar->toInt());
                    $actor = '__set__';
                } else {
                    $actor = VmString::coerceStringBuiltinArg(
                        $frame->calledArgs[5],
                        'SoapHeader::__construct',
                        5,
                        'actor'
                    );
                }
            }
        }

        $receiver->getProperty('namespace')->string($namespace);
        $receiver->getProperty('name')->string($name);
        if (null === $data || Variable::TYPE_NULL === $data->type) {
            $receiver->getProperty('data')->null();
        } else {
            $receiver->getProperty('data')->copyFrom($data);
        }
        $receiver->getProperty('mustUnderstand')->bool($mustUnderstand);
        if (null === $actor) {
            $receiver->getProperty('actor')->null();
        } elseif ('__set__' !== $actor) {
            $receiver->getProperty('actor')->string($actor);
        }
        $receiver->constructed = true;
    }
}
