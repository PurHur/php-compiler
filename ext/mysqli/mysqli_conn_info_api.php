<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * Procedural mysqli connection metadata / SSL helpers (#22194).
 *
 * php-src: ext/mysqli/mysqli.stub.php + mysqli_api.c / mysqli_nonapi.c
 */

abstract class MysqliConnInfoBuiltin extends Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \Error($this->getName().'() is not implemented for JIT (issue #22194)');
    }
}

/** mysqli_insert_id() — php-src ext/mysqli/mysqli_api.c (#22194). */
final class mysqli_insert_id extends MysqliConnInfoBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_insert_id');
    }

    public function execute(Frame $frame): void
    {
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_insert_id');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_insert_id() requires VM context');
        if (null !== $frame->returnVar) {
            VmMysqli::assignInsertId($frame->returnVar, VmMysqli::insertIdOnLink($obj, $ctx));
        }
    }
}

/** mysqli_field_count() — php-src ext/mysqli/mysqli_api.c (#22194). */
final class mysqli_field_count extends MysqliConnInfoBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_field_count');
    }

    public function execute(Frame $frame): void
    {
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_field_count');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_field_count() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmMysqli::fieldCountOnLink($obj, $ctx));
        }
    }
}

/** mysqli_sqlstate() — php-src ext/mysqli/mysqli_api.c (#22194). */
final class mysqli_sqlstate extends MysqliConnInfoBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_sqlstate');
    }

    public function execute(Frame $frame): void
    {
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_sqlstate');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_sqlstate() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmMysqli::sqlstateOnLink($obj, $ctx));
        }
    }
}

/** mysqli_warning_count() — php-src ext/mysqli/mysqli_api.c (#22194). */
final class mysqli_warning_count extends MysqliConnInfoBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_warning_count');
    }

    public function execute(Frame $frame): void
    {
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_warning_count');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_warning_count() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmMysqli::warningCountOnLink($obj, $ctx));
        }
    }
}

/** mysqli_character_set_name() — php-src ext/mysqli/mysqli_api.c (#22194). */
final class mysqli_character_set_name extends MysqliConnInfoBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_character_set_name');
    }

    public function execute(Frame $frame): void
    {
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_character_set_name');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_character_set_name() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmMysqli::characterSetNameOnLink($obj, $ctx));
        }
    }
}

/** mysqli_get_charset() — php-src ext/mysqli/mysqli_api.c (#22194). */
final class mysqli_get_charset extends MysqliConnInfoBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_get_charset');
    }

    public function execute(Frame $frame): void
    {
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_get_charset');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_get_charset() requires VM context');
        if (null === $frame->returnVar) {
            return;
        }
        $charset = VmMysqli::getCharsetOnLink($obj, $ctx);
        if (null === $charset) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->object($charset);
        }
    }
}

/** mysqli_get_server_info() — php-src ext/mysqli/mysqli_api.c (#22194). */
final class mysqli_get_server_info extends MysqliConnInfoBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_get_server_info');
    }

    public function execute(Frame $frame): void
    {
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_get_server_info');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_get_server_info() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmMysqli::serverInfoOnLink($obj, $ctx));
        }
    }
}

/** mysqli_get_host_info() — php-src ext/mysqli/mysqli_api.c (#22194). */
final class mysqli_get_host_info extends MysqliConnInfoBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_get_host_info');
    }

    public function execute(Frame $frame): void
    {
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_get_host_info');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_get_host_info() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmMysqli::hostInfoOnLink($obj, $ctx));
        }
    }
}

/** mysqli_get_proto_info() — php-src ext/mysqli/mysqli_api.c (#22194). */
final class mysqli_get_proto_info extends MysqliConnInfoBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_get_proto_info');
    }

    public function execute(Frame $frame): void
    {
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_get_proto_info');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_get_proto_info() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmMysqli::protoInfoOnLink($obj, $ctx));
        }
    }
}

/** mysqli_get_client_info() — php-src ext/mysqli/mysqli_api.c (#22194). */
final class mysqli_get_client_info extends MysqliConnInfoBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_get_client_info');
    }

    public function execute(Frame $frame): void
    {
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmMysqli::clientInfo());
        }
    }
}

/** mysqli_get_client_version() — php-src ext/mysqli/mysqli_api.c (#22194). */
final class mysqli_get_client_version extends MysqliConnInfoBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_get_client_version');
    }

    public function execute(Frame $frame): void
    {
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmMysqli::clientVersion());
        }
    }
}

/** mysqli_get_server_version() — php-src ext/mysqli/mysqli_api.c (#22194). */
final class mysqli_get_server_version extends MysqliConnInfoBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_get_server_version');
    }

    public function execute(Frame $frame): void
    {
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_get_server_version');
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_get_server_version() requires VM context');
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmMysqli::serverVersionOnLink($obj, $ctx));
        }
    }
}

/** mysqli_ssl_set() — php-src ext/mysqli/mysqli_api.c (#22194). */
final class mysqli_ssl_set extends MysqliConnInfoBuiltin
{
    public function __construct()
    {
        parent::__construct('mysqli_ssl_set');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 6) {
            throw new \ArgumentCountError('mysqli_ssl_set() expects exactly 6 arguments, '.\count($frame->calledArgs).' given');
        }
        $obj = MysqliProceduralLink::requireLink($frame, 'mysqli_ssl_set', 6);
        $key = self::nullableString($frame->calledArgs[1]);
        $certificate = self::nullableString($frame->calledArgs[2]);
        $caCertificate = self::nullableString($frame->calledArgs[3]);
        $caPath = self::nullableString($frame->calledArgs[4]);
        $cipherAlgos = self::nullableString($frame->calledArgs[5]);
        $ctx = $frame->vmContext ?? throw new \LogicException('mysqli_ssl_set() requires VM context');
        MysqliProceduralLink::setBoolReturn(
            $frame,
            VmMysqli::sslSetOnLink($obj, $ctx, $key, $certificate, $caCertificate, $caPath, $cipherAlgos)
        );
    }

    private static function nullableString(Variable $var): ?string
    {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return null;
        }

        return $resolved->toString();
    }
}
