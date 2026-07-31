<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dba;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * dba extension module entry (php-src ext/dba/dba.c; #4422, #24134).
 *
 * Advertise dba_* / Dba\Connection / extension_loaded('dba') only when
 * {@see DbaExtensionPolicy::advertisesExtension()}.
 * Flatfile/inifile open/CRUD/handlers (PHP-in-PHP; no libdb).
 */
class Module extends ModuleAbstract
{
    public function getExtensionVersion(): string
    {
        return '8.4.0';
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        if (!DbaExtensionPolicy::advertisesBuiltins()) {
            return;
        }
        if (DbaExtensionPolicy::advertisesClasses()) {
            BuiltinClasses::register($runtime->vmContext);
        }
    }

    public function getFunctions(): array
    {
        if (!DbaExtensionPolicy::advertisesBuiltins()) {
            return [];
        }

        return [
            new dba_open(),
            new dba_popen(),
            new dba_close(),
            new dba_insert(),
            new dba_replace(),
            new dba_fetch(),
            new dba_exists(),
            new dba_delete(),
            new dba_handlers(),
            new dba_firstkey(),
            new dba_nextkey(),
            new dba_list(),
            new dba_optimize(),
            new dba_sync(),
            new dba_key_split(),
        ];
    }
}
