<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uuid;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * uuid extension module entry (php/pecl-networking-uuid; issue #5910).
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        foreach (UuidConstants::registeredConstants() as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        return [
            new uuid_create(),
            new uuid_generate(),
        ];
    }
}
