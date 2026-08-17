<?php

declare(strict_types=1);

namespace PHPCompiler\ext\soap;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ExceptionSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmString;

/**
 * SoapFault::__construct — php-src zim_SoapFault___construct (ext/soap/soap.c; #20124).
 *
 * Signature: (array|string|null $code, string $string, ?string $actor = null, mixed $details = null, …)
 */
final class SoapFaultConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('SoapFault::__construct() called without $this');
        }
        $receiverVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiverVar->type) {
            throw new \LogicException('SoapFault::__construct() called without $this');
        }
        $receiver = $receiverVar->toObject();

        $faultcode = '';
        $faultcodens = null;
        if (\array_key_exists(1, $frame->calledArgs)) {
            $codeVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $codeVar->type) {
                if (Variable::TYPE_ARRAY === $codeVar->type) {
                    // php-src zim_SoapFault___construct: HashTable of 2 string indexes
                    // (ns, code) → fault_code_ns + fault_code (#31956).
                    [$faultcodens, $faultcode] = self::parseArrayFaultCode($codeVar);
                } else {
                    $faultcode = VmString::coerceStringBuiltinArg(
                        $frame->calledArgs[1],
                        'SoapFault::__construct',
                        1,
                        'code'
                    );
                }
            }
        }

        $faultstring = '';
        if (\array_key_exists(2, $frame->calledArgs)) {
            $strVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $strVar->type) {
                $faultstring = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[2],
                    'SoapFault::__construct',
                    2,
                    'string'
                );
            }
        }

        $actor = null;
        if (\array_key_exists(3, $frame->calledArgs)) {
            $actorVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $actorVar->type) {
                $actor = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[3],
                    'SoapFault::__construct',
                    3,
                    'actor'
                );
            }
        }

        $detail = null;
        if (\array_key_exists(4, $frame->calledArgs)) {
            $detail = $frame->calledArgs[4]->resolveIndirect();
        }

        $name = null;
        if (\array_key_exists(5, $frame->calledArgs)) {
            $nameVar = $frame->calledArgs[5]->resolveIndirect();
            if (Variable::TYPE_NULL !== $nameVar->type) {
                $name = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[5],
                    'SoapFault::__construct',
                    5,
                    'name'
                );
            }
        }

        $headerFault = null;
        if (\array_key_exists(6, $frame->calledArgs)) {
            $headerFault = $frame->calledArgs[6]->resolveIndirect();
        }

        $lang = '';
        if (\array_key_exists(7, $frame->calledArgs)) {
            $langVar = $frame->calledArgs[7]->resolveIndirect();
            if (Variable::TYPE_NULL !== $langVar->type) {
                $lang = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[7],
                    'SoapFault::__construct',
                    7,
                    'lang'
                );
            }
        }

        self::apply(
            $receiver,
            $faultcode,
            $faultstring,
            $actor,
            $detail,
            $frame,
            $faultcodens,
            $name,
            $headerFault,
            $lang
        );
    }

    /**
     * php-src zim_SoapFault___construct array branch (ext/soap/soap.c; #31956).
     *
     * @return array{0: string, 1: string} [faultcodens, faultcode]
     */
    private static function parseArrayFaultCode(Variable $codeVar): array
    {
        $ht = $codeVar->toArray();
        $nsVar = $ht->find('0');
        $codeElem = $ht->find('1');
        if (
            2 !== $ht->getNumElements()
            || null === $nsVar
            || null === $codeElem
        ) {
            throw new \ValueError(
                'SoapFault::__construct(): Argument #1 ($code) is not a valid fault code'
            );
        }
        $nsVar = $nsVar->resolveIndirect();
        $codeElem = $codeElem->resolveIndirect();
        if (
            Variable::TYPE_STRING !== $nsVar->type
            || Variable::TYPE_STRING !== $codeElem->type
            || '' === $codeElem->toString()
        ) {
            throw new \ValueError(
                'SoapFault::__construct(): Argument #1 ($code) is not a valid fault code'
            );
        }

        return [$nsVar->toString(), $codeElem->toString()];
    }

    public static function apply(
        ObjectEntry $receiver,
        string $faultcode,
        string $faultstring,
        ?string $actor,
        ?Variable $detail,
        Frame $frame,
        ?string $faultcodens = null,
        ?string $name = null,
        ?Variable $headerFault = null,
        string $lang = ''
    ): void {
        $message = '' !== $faultstring ? $faultstring : $faultcode;
        $receiver->getProperty(ExceptionSupport::PROP_MESSAGE)->string($message);
        $receiver->getProperty(ExceptionSupport::PROP_CODE)->int(0);
        $receiver->getProperty(ExceptionSupport::PROP_FILE)->string(ExceptionSupport::throwSiteFile($frame));
        $receiver->getProperty(ExceptionSupport::PROP_LINE)->int(ExceptionSupport::throwSiteLine($frame));

        $codeProp = $receiver->getProperty('faultcode');
        $codeProp->string($faultcode);
        if ($receiver->hasProperty('faultcodens')) {
            if (null === $faultcodens) {
                $receiver->getProperty('faultcodens')->null();
            } else {
                $receiver->getProperty('faultcodens')->string($faultcodens);
            }
        }
        $stringProp = $receiver->getProperty('faultstring');
        $stringProp->string($faultstring);
        if (null === $actor) {
            $receiver->getProperty('faultactor')->null();
        } else {
            $receiver->getProperty('faultactor')->string($actor);
        }
        if (null === $detail || Variable::TYPE_NULL === $detail->type) {
            $receiver->getProperty('detail')->null();
        } else {
            $receiver->getProperty('detail')->copyFrom($detail);
        }
        if ($receiver->hasProperty('_name')) {
            if (null === $name) {
                $receiver->getProperty('_name')->null();
            } else {
                $receiver->getProperty('_name')->string($name);
            }
        }
        if ($receiver->hasProperty('headerfault')) {
            if (null === $headerFault || Variable::TYPE_NULL === $headerFault->type) {
                $receiver->getProperty('headerfault')->null();
            } else {
                $receiver->getProperty('headerfault')->copyFrom($headerFault);
            }
        }
        if ($receiver->hasProperty('lang')) {
            $receiver->getProperty('lang')->string($lang);
        }
        $receiver->constructed = true;
    }
}
