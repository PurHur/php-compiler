<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClassProperty;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * php_user_filter internal base class (php-src ext/standard/streams.c; #11747).
 *
 * User stream filters extend this class and register via stream_filter_register().
 */
final class PhpUserFilterBuiltin
{
    public const CLASS_LC = 'php_user_filter';

    public const PROP_FILTERNAME = 'filtername';

    public const PROP_PARAMS = 'params';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $strProto = new Variable(Variable::TYPE_STRING);
        $arrProto = new Variable(Variable::TYPE_ARRAY);

        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('php_user_filter');
        $entry->properties[] = new ClassProperty(self::PROP_FILTERNAME, null, $strProto);
        $entry->properties[] = new ClassProperty(self::PROP_PARAMS, null, $arrProto);

        $entry->methods['filter'] = new PhpUserFilterFilter();
        $entry->methodVisibility['filter'] = $pub;
        $entry->methods['oncreate'] = new PhpUserFilterOnCreate();
        $entry->methodVisibility['oncreate'] = $pub;
        $entry->methods['onclose'] = new PhpUserFilterOnClose();
        $entry->methodVisibility['onclose'] = $pub;

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function isSubclassOf(Context $ctx, string $className): bool
    {
        self::registerClass($ctx);
        $lc = strtolower($className);
        $current = $lc;
        while ('' !== $current) {
            if (self::CLASS_LC === $current) {
                return true;
            }
            $entry = $ctx->classes[$current] ?? null;
            if (null === $entry || null === $entry->parentLc) {
                return false;
            }
            $current = $entry->parentLc;
        }

        return false;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['filter'], $entry->methods['oncreate'], $entry->methods['onclose']);
    }
}

final class PhpUserFilterFilter extends VmClassMethod
{
    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(StdlibConstants::PSFS_PASS_ON);
    }
}

final class PhpUserFilterOnCreate extends VmClassMethod
{
    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(true);
    }
}

final class PhpUserFilterOnClose extends VmClassMethod
{
    public function execute(Frame $frame): void
    {
    }
}
