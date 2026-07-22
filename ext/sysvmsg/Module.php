<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvmsg;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;
use PHPCompiler\VM\Context;

/**
 * sysvmsg extension module entry (php-src ext/sysvmsg/sysvmsg.c; #3666).
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        self::registerClasses($runtime->vmContext);
        foreach (SysvmsgConstants::registeredConstants() as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public static function registerClasses(Context $ctx): void
    {
        VmMsg::registerClass($ctx);
    }

    public function getFunctions(): array
    {
        return [
            new msg_get_queue(),
            new msg_send(),
            new msg_receive(),
            new msg_remove_queue(),
            new msg_stat_queue(),
            new msg_set_queue(),
            new msg_queue_exists(),
        ];
    }
}
