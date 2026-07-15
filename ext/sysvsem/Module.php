<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvsem;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM\Context;

/**
 * sysvsem extension module entry (php-src ext/sysvsem/sysvsem.c; #3704).
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        self::registerClasses($runtime->vmContext);
    }

    public static function registerClasses(Context $ctx): void
    {
        VmSem::registerClass($ctx);
    }

    public function getFunctions(): array
    {
        return [
            new sem_get(),
            new sem_acquire(),
            new sem_release(),
            new sem_remove(),
        ];
    }
}
