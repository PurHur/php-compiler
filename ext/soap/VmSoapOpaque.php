<?php

declare(strict_types=1);

namespace PHPCompiler\ext\soap;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Soap\Url / Soap\Sdl — PHP 8.4 resource→object opaque types (php-src ext/soap/soap.stub.php; #23230).
 *
 * Final internal classes; userland `new` rejected via {@see \PHPCompiler\VM\ReservedBuiltinClass}.
 */
final class VmSoapOpaque
{
    public const URL_CLASS = 'Soap\\Url';

    public const URL_CLASS_LC = 'soap\\url';

    public const SDL_CLASS = 'Soap\\Sdl';

    public const SDL_CLASS_LC = 'soap\\sdl';

    public static function register(Context $ctx): void
    {
        if (!SoapExtensionPolicy::advertisesOpaqueUrlSdlTypes()) {
            return;
        }

        self::registerOne($ctx, self::URL_CLASS, self::URL_CLASS_LC);
        self::registerOne($ctx, self::SDL_CLASS, self::SDL_CLASS_LC);
    }

    private static function registerOne(Context $ctx, string $name, string $lc): void
    {
        if (isset($ctx->classes[$lc])) {
            $ctx->classes[$lc]->isInternal = true;
            $ctx->classes[$lc]->isFinal = true;

            return;
        }

        $entry = new ClassEntry($name);
        $entry->isInternal = true;
        $entry->isFinal = true;
        $ctx->classes[$lc] = $entry;
    }

    /** Factory for SoapClient::$httpurl / related wiring (php-src soap_url_object_create). */
    public static function newUrlObject(Context $ctx): ObjectEntry
    {
        self::register($ctx);
        $class = $ctx->classes[self::URL_CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('Soap\\Url is not registered in this compiler build');
        }
        $object = new ObjectEntry($class);
        $object->constructed = true;

        return $object;
    }

    /** Factory for SoapClient::$sdl (php-src soap_sdl_object_create). */
    public static function newSdlObject(Context $ctx): ObjectEntry
    {
        self::register($ctx);
        $class = $ctx->classes[self::SDL_CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('Soap\\Sdl is not registered in this compiler build');
        }
        $object = new ObjectEntry($class);
        $object->constructed = true;

        return $object;
    }

    public static function urlVariable(Context $ctx): Variable
    {
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object(self::newUrlObject($ctx));

        return $var;
    }

    public static function sdlVariable(Context $ctx): Variable
    {
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object(self::newSdlObject($ctx));

        return $var;
    }
}
