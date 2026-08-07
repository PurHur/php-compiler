<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uploadprogress;

use PHPCompiler\ModuleAbstract;

/**
 * uploadprogress extension module entry (PECL ext/uploadprogress; #6386, #26744).
 *
 * Advertise uploadprogress_* / extension_loaded('uploadprogress') only when
 * {@see UploadprogressExtensionPolicy::advertisesExtension()}.
 * PHP-in-PHP builtins; no runtime/*.c.
 */
class Module extends ModuleAbstract
{
    public function getFunctions(): array
    {
        if (!UploadprogressExtensionPolicy::advertisesBuiltins()) {
            return [];
        }

        return [
            new uploadprogress_get_info(),
            new uploadprogress_get_contents(),
        ];
    }
}
