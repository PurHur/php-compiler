<?php

declare(strict_types=1);

namespace PHPCompiler\ext\soap;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/** Register ext/soap builtin classes (php-src ext/soap/soap.stub.php; #20037, #20124). */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        if (!SoapExtensionPolicy::advertisesExtension()) {
            return;
        }

        $before = \array_keys($ctx->classes);
        self::patchSoapFault($ctx);
        VmSoapEncoding::register($ctx);
        VmSoapServer::registerClass($ctx);
        VmSoapClient::registerClass($ctx);
        // PHP 8.4+ Soap\Url / Soap\Sdl opaque types (#23230).
        VmSoapOpaque::register($ctx);
        foreach (\array_diff(\array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
        if (isset($ctx->classes['soapfault'])) {
            $ctx->classes['soapfault']->isInternal = true;
        }
    }

    /**
     * ThrowableManifest registers SoapFault as a normal Exception; attach SoapFault props + ctor (#20124).
     */
    private static function patchSoapFault(Context $ctx): void
    {
        if (!SoapExtensionPolicy::advertisesExceptionClass()) {
            return;
        }
        if (!isset($ctx->classes['soapfault'])) {
            return;
        }
        $entry = $ctx->classes['soapfault'];
        $pub = CfgFunc::FLAG_PUBLIC;
        $strProto = new Variable(Variable::TYPE_STRING);
        $nullProto = new Variable(Variable::TYPE_NULL);
        $empty = new Variable(Variable::TYPE_STRING);
        $empty->string('');

        $existing = [];
        foreach ($entry->properties as $prop) {
            $existing[\strtolower($prop->name)] = true;
        }
        if (!isset($existing['faultcode'])) {
            $entry->properties[] = new ClassProperty('faultcode', null, $nullProto, false, $pub, 'soapfault');
        }
        if (!isset($existing['faultcodens'])) {
            $entry->properties[] = new ClassProperty('faultcodens', null, $nullProto, false, $pub, 'soapfault');
        }
        if (!isset($existing['faultstring'])) {
            $entry->properties[] = new ClassProperty('faultstring', $empty, $strProto, false, $pub, 'soapfault');
        }
        if (!isset($existing['faultactor'])) {
            $entry->properties[] = new ClassProperty('faultactor', null, $nullProto, false, $pub, 'soapfault');
        }
        if (!isset($existing['detail'])) {
            $entry->properties[] = new ClassProperty('detail', null, $nullProto, false, $pub, 'soapfault');
        }
        if (!isset($existing['_name'])) {
            $entry->properties[] = new ClassProperty('_name', null, $nullProto, false, $pub, 'soapfault');
        }
        if (!isset($existing['headerfault'])) {
            $entry->properties[] = new ClassProperty('headerfault', null, $nullProto, false, $pub, 'soapfault');
        }
        if (!isset($existing['lang'])) {
            $entry->properties[] = new ClassProperty('lang', $empty, $strProto, false, $pub, 'soapfault');
        }

        $ctor = new SoapFaultConstruct();
        $entry->constructor = $ctor;
        $entry->methods['__construct'] = $ctor;
        $entry->methodVisibility['__construct'] = $pub;
    }
}
