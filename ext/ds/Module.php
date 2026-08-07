<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ds;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * ext/ds module — Ds\* collections (#22549 / #28062, php-ds/ext-ds, #25086).
 *
 * Advertise extension_loaded('ds') / Ds\* classes only when
 * {@see DsExtensionPolicy::advertisesExtension()}.
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        if (!DsExtensionPolicy::advertisesClasses()) {
            return;
        }
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getFunctions(): array
    {
        if (!DsExtensionPolicy::advertisesExtension()) {
            return [];
        }
        require_once __DIR__.'/DsFactories.php';

        return [
            new ds_seq(),
            new ds_map(),
            new ds_set(),
            new ds_heap(),
        ];
    }
}
