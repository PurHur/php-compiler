<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** mysqli_connect() — host bridge (php-src ext/mysqli/mysqli_api.c; #3435). */
final class mysqli_connect extends Internal
{
    public function __construct()
    {
        parent::__construct('mysqli_connect');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $argc = \count($frame->calledArgs);
        $hostname = $argc >= 1 ? self::strNull($frame->calledArgs[0]) : null;
        $username = $argc >= 2 ? self::strNull($frame->calledArgs[1]) : null;
        $password = $argc >= 3 ? self::strNull($frame->calledArgs[2]) : null;
        $database = $argc >= 4 ? self::strNull($frame->calledArgs[3]) : null;
        $port = $argc >= 5 ? self::intNull($frame->calledArgs[4]) : null;
        $socket = $argc >= 6 ? self::strNull($frame->calledArgs[5]) : null;

        if (!MysqliExtensionPolicy::hasNativeDriver()) {
            VmMysqli::setConnectError(2002, 'mysqli extension not available on host PHP');
            $frame->returnVar->bool(false);

            return;
        }

        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_connect requires VM context');

        $hostname = $hostname ?? ini_get('mysqli.default_host') ?: '127.0.0.1';
        $username = $username ?? ini_get('mysqli.default_user') ?: '';
        $password = $password ?? ini_get('mysqli.default_pw') ?: '';
        $database = $database ?? '';
        $port = $port ?? (int) (ini_get('mysqli.default_port') ?: 3306);
        $socket = $socket ?? ini_get('mysqli.default_socket') ?: '';

        try {
            $native = @new \mysqli($hostname, $username, $password, $database, $port, $socket);
        } catch (\mysqli_sql_exception $e) {
            $frame->returnVar->bool(false);

            return;
        }

        if ($native->connect_errno) {
            $frame->returnVar->bool(false);

            return;
        }

        $frame->returnVar->object(VmMysqli::wrapNativeWithContext($ctx, $native));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error('mysqli_connect() is not implemented for JIT (issue #3435)');
    }

    private static function strNull(Variable $var): ?string
    {
        $r = $var->resolveIndirect();

        return Variable::TYPE_NULL === $r->type ? null : $r->toString();
    }

    private static function intNull(Variable $var): ?int
    {
        $r = $var->resolveIndirect();

        return Variable::TYPE_NULL === $r->type ? null : $r->toInt();
    }
}
