<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvshm;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM\Context;

/**
 * sysvshm extension module entry (php-src ext/sysvshm/sysvshm.c; #6436).
 *
 * php-src keeps {@code shmop} as a separate zend_module_entry (ext/shmop/shmop.c).
 * This tree hosts both APIs under one Module for bootstrap; advertise logical
 * {@code shmop} via {@see getAdditionalExtensionNames()} so extension_loaded /
 * get_extension_funcs match Zend dual advertisement (#22426).
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        self::registerClasses($runtime->vmContext);
    }

    /**
     * @return list<string>
     */
    public function getAdditionalExtensionNames(): array
    {
        return ['shmop'];
    }

    public static function registerClasses(Context $ctx): void
    {
        VmSysvShm::registerClass($ctx);
        VmShmop::registerClass($ctx);
    }

    public function getFunctions(): array
    {
        return [
            new shm_attach(),
            new shm_detach(),
            new shm_get_var(),
            new shm_has_var(),
            new shm_put_var(),
            new shm_remove(),
            new shm_remove_var(),
            new shmop_open(),
            new shmop_read(),
            new shmop_write(),
            new shmop_size(),
            new shmop_close(),
            new shmop_delete(),
        ];
    }
}
