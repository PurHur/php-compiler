<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uuid;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * uuid extension module entry (php/pecl-networking-uuid; #5910 / #22228 / #23962 / #27836).
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        if (!UuidExtensionPolicy::advertisesExtension()) {
            return;
        }
        foreach (UuidConstants::registeredConstants() as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        if (!UuidExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return [
            new uuid_create(),
            new uuid_generate(),
            new uuid_generate_md5(),
            new uuid_generate_sha1(),
            new uuid_is_valid(),
            new uuid_parse(),
            new uuid_unparse(),
            new uuid_compare(),
            new uuid_is_null(),
            new uuid_type(),
            new uuid_variant(),
            new uuid_time(),
            new uuid_mac(),
        ];
    }
}
