<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * StreamError readonly value object (php-src main/streams/stream_errors.stub.php; #21020).
 */
final class StreamErrorBuiltin
{
    public const CLASS_LC = 'streamerror';

    public const PROP_CODE = 'code';

    public const PROP_MESSAGE = 'message';

    public const PROP_WRAPPER_NAME = 'wrapperName';

    public const PROP_SEVERITY = 'severity';

    public const PROP_TERMINATING = 'terminating';

    public const PROP_PARAM = 'param';

    public static function registerClass(Context $ctx): void
    {
        if (!CompilerVersion::supportsStreamErrorApi()) {
            return;
        }
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('StreamError');
        $entry->readonly = true;
        $entry->isFinal = true;
        $entry->isInternal = true;

        $objProto = new Variable(Variable::TYPE_OBJECT);
        $strProto = new Variable(Variable::TYPE_STRING);
        $intProto = new Variable(Variable::TYPE_INTEGER);
        $boolProto = new Variable(Variable::TYPE_BOOLEAN);
        $nullProto = new Variable(Variable::TYPE_NULL);
        $pub = CfgFunc::FLAG_PUBLIC;

        $entry->properties[] = new ClassProperty(self::PROP_CODE, null, $objProto, true, $pub, self::CLASS_LC);
        $entry->properties[] = new ClassProperty(self::PROP_MESSAGE, null, $strProto, true, $pub, self::CLASS_LC);
        $entry->properties[] = new ClassProperty(self::PROP_WRAPPER_NAME, null, $strProto, true, $pub, self::CLASS_LC);
        $entry->properties[] = new ClassProperty(self::PROP_SEVERITY, null, $intProto, true, $pub, self::CLASS_LC);
        $entry->properties[] = new ClassProperty(self::PROP_TERMINATING, null, $boolProto, true, $pub, self::CLASS_LC);
        $entry->properties[] = new ClassProperty(self::PROP_PARAM, $nullProto, $strProto, true, $pub, self::CLASS_LC);

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function createObject(
        Context $ctx,
        string $codeCase,
        string $message,
        string $wrapperName,
        int $severity,
        bool $terminating,
        ?string $param
    ): Variable {
        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('StreamError is not registered in this compiler build');
        }
        $enum = $ctx->classes['streamerrorcode'] ?? null;
        if (null === $enum) {
            throw new \LogicException('StreamErrorCode is not registered in this compiler build');
        }

        $object = new ObjectEntry($class);
        $codeVar = new Variable();
        EnumCaseSupport::fetchCaseByMemberName($enum, strtolower($codeCase), $codeVar, $ctx);
        $object->getProperty(self::PROP_CODE)->copyFrom($codeVar);
        $object->getProperty(self::PROP_MESSAGE)->string($message);
        $object->getProperty(self::PROP_WRAPPER_NAME)->string($wrapperName);
        $object->getProperty(self::PROP_SEVERITY)->int($severity);
        $object->getProperty(self::PROP_TERMINATING)->bool($terminating);
        if (null === $param) {
            $object->getProperty(self::PROP_PARAM)->null();
        } else {
            $object->getProperty(self::PROP_PARAM)->string($param);
        }
        $object->constructed = true;

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }
}
