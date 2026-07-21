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
        foreach ([
            'MSG_IPC_NOWAIT' => \defined('MSG_IPC_NOWAIT') ? (int) \constant('MSG_IPC_NOWAIT') : 1,
            'MSG_EAGAIN' => \defined('MSG_EAGAIN') ? (int) \constant('MSG_EAGAIN') : 11,
            'MSG_ENOMSG' => \defined('MSG_ENOMSG') ? (int) \constant('MSG_ENOMSG') : 42,
            'MSG_NOERROR' => \defined('MSG_NOERROR') ? (int) \constant('MSG_NOERROR') : 2,
            'MSG_EXCEPT' => \defined('MSG_EXCEPT') ? (int) \constant('MSG_EXCEPT') : 4,
        ] as $name => $value) {
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
