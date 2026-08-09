<?php

declare(strict_types=1);

namespace PHPCompiler\ext\wddx;

use PHPCompiler\ModuleAbstract;

/**
 * wddx extension module entry (php-src ext/wddx/wddx.c; #6327).
 *
 * PHP-in-PHP WDDX serialize/deserialize — no runtime/*.c growth. Advertise logical {@code wddx}
 * extension when {@see WddxExtensionPolicy::advertisesExtension()} — withheld on reference profile
 * (Zend 8.2 has no ext/wddx).
 */
class Module extends ModuleAbstract
{
    /** php-src ext/wddx/php_wddx.h PHP_WDDX_VERSION */
    private const WDDX_VERSION = '1.0.0';

    public function getExtensionVersion(): string
    {
        return self::WDDX_VERSION;
    }

    public function getFunctions(): array
    {
        if (!WddxExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return [
            new wddx_serialize_value(),
            new wddx_serialize_vars(),
            new wddx_deserialize(),
            new wddx_packet_start(),
            new wddx_add_vars(),
            new wddx_packet_end(),
        ];
    }
}
