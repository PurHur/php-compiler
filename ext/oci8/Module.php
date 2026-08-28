<?php

declare(strict_types=1);

namespace PHPCompiler\ext\oci8;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * oci8 extension module entry (php-src ext/oci8/oci8.c; #6441).
 *
 * Phase-0 procedural API — PHP-in-PHP; host ext/oci8 bridge when present.
 * Without Oracle Instant Client, connect raises a catchable {@see \Error}.
 */
class Module extends ModuleAbstract
{
    private const OCI8_VERSION = '3.3.0';

    public function getExtensionVersion(): string
    {
        return self::OCI8_VERSION;
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
    }

    public function getFunctions(): array
    {
        if (!Oci8ExtensionPolicy::advertisesBuiltins()) {
            return [];
        }

        require_once __DIR__.'/oci8_builtins.php';

        return [
            new oci_connect(),
            new oci_parse(),
            new oci_execute(),
            new oci_fetch_array(),
        ];
    }
}
