<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ds;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * ext/ds module — Ds\Vector / Ds\Map / Ds\Set MVP (#22549, php-ds/ext-ds, #25086).
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
        return [];
    }
}
