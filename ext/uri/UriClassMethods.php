<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uri;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** Uri\Rfc3986\Uri::parse() — VM (#9051, ext/uri/php_uri.stub.php). */
final class Rfc3986UriParse extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('parse');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Uri\\Rfc3986\\Uri::parse() requires VM context');
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('Uri\\Rfc3986\\Uri::parse() expects at least 1 argument, 0 given');
        }
        $uri = VmReflection::stringArg($frame->calledArgs[0], 'Uri\\Rfc3986\\Uri::parse() uri', 0);
        $parsed = VmUri::tryParseRfc3986($ctx, $uri);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($parsed): void {
            if (null === $parsed) {
                $ret->null();
            } else {
                $ret->copyFrom($parsed);
            }
        });
    }
}

abstract class Rfc3986UriGetter extends VmClassMethod
{
    public function __construct(string $name)
    {
        parent::__construct($name);
    }

    protected function receiverState(Frame $frame): array
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($this->getName().'() called without $this');
        }
        $self = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $self->type) {
            throw new \LogicException($this->getName().'() called without $this');
        }

        return VmUri::rfc3986State($self->toObject());
    }
}

final class Rfc3986UriGetHost extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('getHost');
    }

    public function execute(Frame $frame): void
    {
        $host = $this->receiverState($frame)['host'] ?? null;
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($host): void {
            if (null === $host) {
                $ret->null();
            } else {
                $ret->string($host);
            }
        });
    }
}

final class Rfc3986UriGetPath extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('getPath');
    }

    public function execute(Frame $frame): void
    {
        $path = (string) ($this->receiverState($frame)['path'] ?? '');
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($path): void {
            $ret->string($path);
        });
    }
}

final class Rfc3986UriGetScheme extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('getScheme');
    }

    public function execute(Frame $frame): void
    {
        $scheme = $this->receiverState($frame)['scheme'] ?? null;
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($scheme): void {
            if (null === $scheme) {
                $ret->null();
            } else {
                $ret->string($scheme);
            }
        });
    }
}

final class Rfc3986UriToString extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('toString');
    }

    public function execute(Frame $frame): void
    {
        $uri = VmUri::composeUrlString($this->receiverState($frame), true);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($uri): void {
            $ret->string($uri);
        });
    }
}

final class Rfc3986UriToRawString extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('toRawString');
    }

    public function execute(Frame $frame): void
    {
        // MVP: no percent-decode distinction from toString() (#20614).
        $uri = VmUri::composeUrlString($this->receiverState($frame), true);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($uri): void {
            $ret->string($uri);
        });
    }
}

final class Rfc3986UriGetQuery extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('getQuery');
    }

    public function execute(Frame $frame): void
    {
        $query = $this->receiverState($frame)['query'] ?? null;
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($query): void {
            if (null === $query) {
                $ret->null();
            } else {
                $ret->string($query);
            }
        });
    }
}

final class Rfc3986UriGetRawQuery extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('getRawQuery');
    }

    public function execute(Frame $frame): void
    {
        $query = $this->receiverState($frame)['query'] ?? null;
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($query): void {
            if (null === $query) {
                $ret->null();
            } else {
                $ret->string($query);
            }
        });
    }
}

final class Rfc3986UriGetFragment extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('getFragment');
    }

    public function execute(Frame $frame): void
    {
        $fragment = $this->receiverState($frame)['fragment'] ?? null;
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($fragment): void {
            if (null === $fragment) {
                $ret->null();
            } else {
                $ret->string($fragment);
            }
        });
    }
}

final class Rfc3986UriGetRawFragment extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('getRawFragment');
    }

    public function execute(Frame $frame): void
    {
        $fragment = $this->receiverState($frame)['fragment'] ?? null;
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($fragment): void {
            if (null === $fragment) {
                $ret->null();
            } else {
                $ret->string($fragment);
            }
        });
    }
}

final class Rfc3986UriGetPort extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('getPort');
    }

    public function execute(Frame $frame): void
    {
        $port = $this->receiverState($frame)['port'] ?? null;
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($port): void {
            if (null === $port) {
                $ret->null();
            } else {
                $ret->int((int) $port);
            }
        });
    }
}

final class Rfc3986UriGetUserInfo extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('getUserInfo');
    }

    public function execute(Frame $frame): void
    {
        $ui = $this->receiverState($frame)['userinfo'] ?? null;
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ui): void {
            if (null === $ui) {
                $ret->null();
            } else {
                $ret->string($ui);
            }
        });
    }
}

final class Rfc3986UriGetRawUserInfo extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('getRawUserInfo');
    }

    public function execute(Frame $frame): void
    {
        $ui = $this->receiverState($frame)['userinfo'] ?? null;
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ui): void {
            if (null === $ui) {
                $ret->null();
            } else {
                $ret->string($ui);
            }
        });
    }
}

final class Rfc3986UriGetUsername extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('getUsername');
    }

    public function execute(Frame $frame): void
    {
        $user = $this->receiverState($frame)['username'] ?? null;
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($user): void {
            if (null === $user) {
                $ret->null();
            } else {
                $ret->string($user);
            }
        });
    }
}

final class Rfc3986UriGetRawUsername extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('getRawUsername');
    }

    public function execute(Frame $frame): void
    {
        $user = $this->receiverState($frame)['username'] ?? null;
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($user): void {
            if (null === $user) {
                $ret->null();
            } else {
                $ret->string($user);
            }
        });
    }
}

final class Rfc3986UriGetPassword extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('getPassword');
    }

    public function execute(Frame $frame): void
    {
        $pass = $this->receiverState($frame)['password'] ?? null;
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($pass): void {
            if (null === $pass) {
                $ret->null();
            } else {
                $ret->string($pass);
            }
        });
    }
}

final class Rfc3986UriGetRawPassword extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('getRawPassword');
    }

    public function execute(Frame $frame): void
    {
        $pass = $this->receiverState($frame)['password'] ?? null;
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($pass): void {
            if (null === $pass) {
                $ret->null();
            } else {
                $ret->string($pass);
            }
        });
    }
}

final class Rfc3986UriGetRawScheme extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('getRawScheme');
    }

    public function execute(Frame $frame): void
    {
        $scheme = $this->receiverState($frame)['scheme'] ?? null;
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($scheme): void {
            if (null === $scheme) {
                $ret->null();
            } else {
                $ret->string($scheme);
            }
        });
    }
}

final class Rfc3986UriGetRawHost extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('getRawHost');
    }

    public function execute(Frame $frame): void
    {
        $state = $this->receiverState($frame);
        $host = $state['rawHost'] ?? $state['host'] ?? null;
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($host): void {
            if (null === $host) {
                $ret->null();
            } else {
                $ret->string($host);
            }
        });
    }
}

final class Rfc3986UriGetRawPath extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('getRawPath');
    }

    public function execute(Frame $frame): void
    {
        $path = (string) ($this->receiverState($frame)['path'] ?? '');
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($path): void {
            $ret->string($path);
        });
    }
}

final class Rfc3986UriWithScheme extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('withScheme');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Uri\\Rfc3986\\Uri::withScheme() requires VM context');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\Rfc3986\\Uri::withScheme() expects exactly 1 argument, 0 given');
        }
        $self = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $arg = $frame->calledArgs[1]->resolveIndirect();
        $scheme = null;
        if (Variable::TYPE_NULL !== $arg->type) {
            $scheme = VmReflection::stringArg($arg, 'Uri\\Rfc3986\\Uri::withScheme() scheme', 0);
        }
        $next = VmUri::rfc3986With($ctx, $self, ['scheme' => $scheme]);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($next): void {
            $ret->copyFrom($next);
        });
    }
}

final class Rfc3986UriWithHost extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('withHost');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Uri\\Rfc3986\\Uri::withHost() requires VM context');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\Rfc3986\\Uri::withHost() expects exactly 1 argument, 0 given');
        }
        $self = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $arg = $frame->calledArgs[1]->resolveIndirect();
        $host = null;
        if (Variable::TYPE_NULL !== $arg->type) {
            $host = VmReflection::stringArg($arg, 'Uri\\Rfc3986\\Uri::withHost() host', 0);
        }
        $next = VmUri::rfc3986With($ctx, $self, ['host' => $host]);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($next): void {
            $ret->copyFrom($next);
        });
    }
}

final class Rfc3986UriWithPort extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('withPort');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Uri\\Rfc3986\\Uri::withPort() requires VM context');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\Rfc3986\\Uri::withPort() expects exactly 1 argument, 0 given');
        }
        $self = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $arg = $frame->calledArgs[1]->resolveIndirect();
        $port = null;
        if (Variable::TYPE_NULL !== $arg->type) {
            if (Variable::TYPE_INTEGER === $arg->type) {
                $port = $arg->toInt();
            } elseif (Variable::TYPE_FLOAT === $arg->type) {
                $port = (int) $arg->toFloat();
            } else {
                throw new \TypeError(
                    'Uri\\Rfc3986\\Uri::withPort(): Argument #1 ($port) must be of type ?int, '
                    .EnumCaseSupport::typeNameForVariable($arg).' given'
                );
            }
        }
        $next = VmUri::rfc3986With($ctx, $self, ['port' => $port]);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($next): void {
            $ret->copyFrom($next);
        });
    }
}

final class Rfc3986UriWithPath extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('withPath');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Uri\\Rfc3986\\Uri::withPath() requires VM context');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\Rfc3986\\Uri::withPath() expects exactly 1 argument, 0 given');
        }
        $self = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $path = VmReflection::stringArg($frame->calledArgs[1], 'Uri\\Rfc3986\\Uri::withPath() path', 0);
        $next = VmUri::rfc3986With($ctx, $self, ['path' => $path]);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($next): void {
            $ret->copyFrom($next);
        });
    }
}

final class Rfc3986UriWithQuery extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('withQuery');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Uri\\Rfc3986\\Uri::withQuery() requires VM context');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\Rfc3986\\Uri::withQuery() expects exactly 1 argument, 0 given');
        }
        $self = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $arg = $frame->calledArgs[1]->resolveIndirect();
        $query = null;
        if (Variable::TYPE_NULL !== $arg->type) {
            $query = VmReflection::stringArg($arg, 'Uri\\Rfc3986\\Uri::withQuery() query', 0);
        }
        $next = VmUri::rfc3986With($ctx, $self, ['query' => $query]);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($next): void {
            $ret->copyFrom($next);
        });
    }
}

final class Rfc3986UriWithFragment extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('withFragment');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Uri\\Rfc3986\\Uri::withFragment() requires VM context');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\Rfc3986\\Uri::withFragment() expects exactly 1 argument, 0 given');
        }
        $self = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $arg = $frame->calledArgs[1]->resolveIndirect();
        $fragment = null;
        if (Variable::TYPE_NULL !== $arg->type) {
            $fragment = VmReflection::stringArg($arg, 'Uri\\Rfc3986\\Uri::withFragment() fragment', 0);
        }
        $next = VmUri::rfc3986With($ctx, $self, ['fragment' => $fragment]);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($next): void {
            $ret->copyFrom($next);
        });
    }
}

final class Rfc3986UriWithUserInfo extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('withUserInfo');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Uri\\Rfc3986\\Uri::withUserInfo() requires VM context');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\Rfc3986\\Uri::withUserInfo() expects exactly 1 argument, 0 given');
        }
        $self = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $arg = $frame->calledArgs[1]->resolveIndirect();
        $userinfo = null;
        if (Variable::TYPE_NULL !== $arg->type) {
            $userinfo = VmReflection::stringArg($arg, 'Uri\\Rfc3986\\Uri::withUserInfo() userinfo', 0);
        }
        $next = VmUri::rfc3986With($ctx, $self, ['userinfo' => $userinfo]);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($next): void {
            $ret->copyFrom($next);
        });
    }
}

final class Rfc3986UriResolve extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('resolve');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Uri\\Rfc3986\\Uri::resolve() requires VM context');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\Rfc3986\\Uri::resolve() expects exactly 1 argument, 0 given');
        }
        $self = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $uri = VmReflection::stringArg($frame->calledArgs[1], 'Uri\\Rfc3986\\Uri::resolve() uri', 0);
        $next = VmUri::rfc3986Resolve($ctx, $self, $uri);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($next): void {
            $ret->copyFrom($next);
        });
    }
}

final class Rfc3986UriEquals extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('equals');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\Rfc3986\\Uri::equals() expects at least 1 argument, 0 given');
        }
        $left = $this->receiverState($frame);
        $otherVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $otherVar->type) {
            throw new \TypeError(
                'Uri\\Rfc3986\\Uri::equals(): Argument #1 ($uri) must be of type Uri\\Rfc3986\\Uri'
            );
        }
        $otherObj = $otherVar->toObject();
        if (VmUri::CLASS_RFC3986_URI !== strtolower($otherObj->class->name)) {
            throw new \TypeError(
                'Uri\\Rfc3986\\Uri::equals(): Argument #1 ($uri) must be of type Uri\\Rfc3986\\Uri, '
                .$otherObj->class->name.' given'
            );
        }
        $right = VmUri::rfc3986State($otherObj);
        $includeFragment = false;
        if (\count($frame->calledArgs) >= 3) {
            $mode = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_OBJECT === $mode->type) {
                $includeFragment = 'IncludeFragment' === ($mode->toObject()->enumCaseName ?? '');
            }
        }
        $eq = VmUri::composeUrlString($left, $includeFragment)
            === VmUri::composeUrlString($right, $includeFragment);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($eq): void {
            $ret->bool($eq);
        });
    }
}

/** Uri\Rfc3986\Uri::__construct — php-src create_rfc3986_uri(is_constructor=true); #21468. */
final class Rfc3986UriConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Uri\\Rfc3986\\Uri::__construct() requires VM context');
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Uri\\Rfc3986\\Uri::__construct() called without $this');
        }
        $self = $frame->calledArgs[0]->resolveIndirect()->toObject();
        if (VmUri::hasRfc3986State($self)) {
            throw new \Error('Cannot modify readonly object of class Uri\\Rfc3986\\Uri');
        }
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\Rfc3986\\Uri::__construct() expects at least 1 argument, 0 given');
        }
        $uri = VmReflection::stringArg($frame->calledArgs[1], 'Uri\\Rfc3986\\Uri::__construct() uri', 0);
        $base = null;
        if (\count($frame->calledArgs) >= 3) {
            $baseArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $baseArg->type) {
                if (Variable::TYPE_OBJECT !== $baseArg->type
                    || VmUri::CLASS_RFC3986_URI !== strtolower($baseArg->toObject()->class->name)
                ) {
                    throw new \TypeError(
                        'Uri\\Rfc3986\\Uri::__construct(): Argument #2 ($baseUrl) must be of type ?Uri\\Rfc3986\\Uri, '
                        .EnumCaseSupport::typeNameForVariable($baseArg).' given'
                    );
                }
                $base = $baseArg->toObject();
            }
        }

        if (null !== $base) {
            $resolved = VmUri::rfc3986Resolve($ctx, $base, $uri);
            VmUri::bindRfc3986State($self, VmUri::rfc3986State($resolved->toObject()));

            return;
        }

        $parts = VmUri::tryParseRfc3986Parts($uri);
        if (null === $parts) {
            throw new \Uri\InvalidUriException('The specified URI is malformed');
        }
        VmUri::bindRfc3986State($self, $parts);
    }
}

/** Uri\Rfc3986\Uri::__serialize — php-src returns [[uri=>raw], []]; #21468. */
final class Rfc3986UriSerialize extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('__serialize');
    }

    public function execute(Frame $frame): void
    {
        $uri = VmUri::composeUrlString($this->receiverState($frame), true);
        $inner = new HashTable();
        $uriVar = new Variable(Variable::TYPE_STRING);
        $uriVar->string($uri);
        $inner->addNew('uri', $uriVar);
        $innerVar = new Variable(Variable::TYPE_ARRAY);
        $innerVar->array($inner);
        $props = new HashTable();
        $propsVar = new Variable(Variable::TYPE_ARRAY);
        $propsVar->array($props);
        $outer = new HashTable();
        $outer->addNewIndex(0, $innerVar);
        $outer->addNewIndex(1, $propsVar);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($outer): void {
            $ret->array($outer);
        });
    }
}

/** Uri\Rfc3986\Uri::__unserialize — php-src uri_unserialize; #21468. */
final class Rfc3986UriUnserialize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__unserialize');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Uri\\Rfc3986\\Uri::__unserialize() called without $this');
        }
        $self = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $className = $self->class->name;
        if (VmUri::hasRfc3986State($self)) {
            throw new \Error('Cannot modify readonly object of class '.$className);
        }
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\Rfc3986\\Uri::__unserialize() expects exactly 1 argument, 0 given');
        }
        $arg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \TypeError(
                'Uri\\Rfc3986\\Uri::__unserialize(): Argument #1 ($data) must be of type array'
            );
        }
        $data = $arg->toArray();
        $pairs = [];
        foreach ($data->iterateKeyed(true) as [$keyVar, $valVar]) {
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $key->type) {
                throw new \Exception('Invalid serialization data for '.$className.' object');
            }
            $pairs[$key->toInt(null)] = $valVar->resolveIndirect();
        }
        if (2 !== \count($pairs) || !isset($pairs[0], $pairs[1])) {
            throw new \Exception('Invalid serialization data for '.$className.' object');
        }
        $stateBag = $pairs[0];
        $propsBag = $pairs[1];
        if (Variable::TYPE_ARRAY !== $stateBag->type || Variable::TYPE_ARRAY !== $propsBag->type) {
            throw new \Exception('Invalid serialization data for '.$className.' object');
        }
        $uriString = null;
        $stateCount = 0;
        foreach ($stateBag->toArray()->iterateKeyed(true) as [$keyVar, $valVar]) {
            ++$stateCount;
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_STRING !== $key->type || 'uri' !== $key->toString(null)) {
                throw new \Exception('Invalid serialization data for '.$className.' object');
            }
            $val = $valVar->resolveIndirect();
            if (Variable::TYPE_STRING !== $val->type) {
                throw new \Exception('Invalid serialization data for '.$className.' object');
            }
            $uriString = $val->toString(null);
        }
        if (1 !== $stateCount || null === $uriString) {
            throw new \Exception('Invalid serialization data for '.$className.' object');
        }
        $propCount = 0;
        foreach ($propsBag->toArray()->iterateKeyed(true) as $_) {
            ++$propCount;
        }
        if ($propCount > 0) {
            throw new \Exception('Invalid serialization data for '.$className.' object');
        }
        $parts = VmUri::tryParseRfc3986Parts($uriString);
        if (null === $parts) {
            throw new \Exception('Invalid serialization data for '.$className.' object');
        }
        VmUri::bindRfc3986State($self, $parts);
    }
}

/** Uri\Rfc3986\Uri::__debugInfo — php-src uri_get_debug_properties; #21468. */
final class Rfc3986UriDebugInfo extends Rfc3986UriGetter
{
    public function __construct()
    {
        parent::__construct('__debugInfo');
    }

    public function execute(Frame $frame): void
    {
        $info = VmUri::debugInfoFromState($this->receiverState($frame));
        $ht = new HashTable();
        foreach ($info as $name => $value) {
            $slot = VmJson::import($value);
            $ht->addNew((string) $name, $slot);
        }
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ht): void {
            $ret->array($ht);
        });
    }
}

final class Rfc3986UriBuilderConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Uri\\Rfc3986\\UriBuilder::__construct() called without $this');
        }
        $self = $frame->calledArgs[0]->resolveIndirect()->toObject();
        VmUri::builderReset($self);
        $self->constructed = true;
    }
}

abstract class Rfc3986UriBuilderMethod extends VmClassMethod
{
    protected function receiver(Frame $frame): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($this->getName().'() called without $this');
        }
        $self = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $self->type) {
            throw new \LogicException($this->getName().'() called without $this');
        }

        return $self->toObject();
    }

    protected function returnSelf(Frame $frame): void
    {
        $selfVar = $frame->calledArgs[0]->resolveIndirect();
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($selfVar): void {
            $ret->copyFrom($selfVar);
        });
    }
}

final class Rfc3986UriBuilderReset extends Rfc3986UriBuilderMethod
{
    public function __construct()
    {
        parent::__construct('reset');
    }

    public function execute(Frame $frame): void
    {
        VmUri::builderReset($this->receiver($frame));
        $this->returnSelf($frame);
    }
}

final class Rfc3986UriBuilderSetScheme extends Rfc3986UriBuilderMethod
{
    public function __construct()
    {
        parent::__construct('setScheme');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\Rfc3986\\UriBuilder::setScheme() expects exactly 1 argument, 0 given');
        }
        $arg = $frame->calledArgs[1]->resolveIndirect();
        $scheme = null;
        if (Variable::TYPE_NULL !== $arg->type) {
            $scheme = VmReflection::stringArg($arg, 'Uri\\Rfc3986\\UriBuilder::setScheme() scheme', 0);
        }
        VmUri::builderApply($this->receiver($frame), ['scheme' => $scheme]);
        $this->returnSelf($frame);
    }
}

final class Rfc3986UriBuilderSetUserInfo extends Rfc3986UriBuilderMethod
{
    public function __construct()
    {
        parent::__construct('setUserInfo');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\Rfc3986\\UriBuilder::setUserInfo() expects exactly 1 argument, 0 given');
        }
        $arg = $frame->calledArgs[1]->resolveIndirect();
        $userinfo = null;
        if (Variable::TYPE_NULL !== $arg->type) {
            $userinfo = VmReflection::stringArg($arg, 'Uri\\Rfc3986\\UriBuilder::setUserInfo() userInfo', 0);
        }
        VmUri::builderApply($this->receiver($frame), ['userinfo' => $userinfo]);
        $this->returnSelf($frame);
    }
}

final class Rfc3986UriBuilderSetHost extends Rfc3986UriBuilderMethod
{
    public function __construct()
    {
        parent::__construct('setHost');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\Rfc3986\\UriBuilder::setHost() expects exactly 1 argument, 0 given');
        }
        $arg = $frame->calledArgs[1]->resolveIndirect();
        $host = null;
        if (Variable::TYPE_NULL !== $arg->type) {
            $host = VmReflection::stringArg($arg, 'Uri\\Rfc3986\\UriBuilder::setHost() host', 0);
        }
        VmUri::builderApply($this->receiver($frame), ['host' => $host]);
        $this->returnSelf($frame);
    }
}

final class Rfc3986UriBuilderSetPort extends Rfc3986UriBuilderMethod
{
    public function __construct()
    {
        parent::__construct('setPort');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\Rfc3986\\UriBuilder::setPort() expects exactly 1 argument, 0 given');
        }
        $arg = $frame->calledArgs[1]->resolveIndirect();
        $port = null;
        if (Variable::TYPE_NULL !== $arg->type) {
            if (Variable::TYPE_INTEGER === $arg->type) {
                $port = $arg->toInt();
            } elseif (Variable::TYPE_FLOAT === $arg->type) {
                $port = (int) $arg->toFloat();
            } else {
                throw new \TypeError(
                    'Uri\\Rfc3986\\UriBuilder::setPort(): Argument #1 ($port) must be of type ?int, '
                    .EnumCaseSupport::typeNameForVariable($arg).' given'
                );
            }
        }
        VmUri::builderApply($this->receiver($frame), ['port' => $port]);
        $this->returnSelf($frame);
    }
}

final class Rfc3986UriBuilderSetPath extends Rfc3986UriBuilderMethod
{
    public function __construct()
    {
        parent::__construct('setPath');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\Rfc3986\\UriBuilder::setPath() expects exactly 1 argument, 0 given');
        }
        $path = VmReflection::stringArg($frame->calledArgs[1], 'Uri\\Rfc3986\\UriBuilder::setPath() path', 0);
        VmUri::builderApply($this->receiver($frame), ['path' => $path]);
        $this->returnSelf($frame);
    }
}

final class Rfc3986UriBuilderSetQuery extends Rfc3986UriBuilderMethod
{
    public function __construct()
    {
        parent::__construct('setQuery');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\Rfc3986\\UriBuilder::setQuery() expects exactly 1 argument, 0 given');
        }
        $arg = $frame->calledArgs[1]->resolveIndirect();
        $query = null;
        if (Variable::TYPE_NULL !== $arg->type) {
            $query = VmReflection::stringArg($arg, 'Uri\\Rfc3986\\UriBuilder::setQuery() query', 0);
        }
        VmUri::builderApply($this->receiver($frame), ['query' => $query]);
        $this->returnSelf($frame);
    }
}

final class Rfc3986UriBuilderSetFragment extends Rfc3986UriBuilderMethod
{
    public function __construct()
    {
        parent::__construct('setFragment');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\Rfc3986\\UriBuilder::setFragment() expects exactly 1 argument, 0 given');
        }
        $arg = $frame->calledArgs[1]->resolveIndirect();
        $fragment = null;
        if (Variable::TYPE_NULL !== $arg->type) {
            $fragment = VmReflection::stringArg($arg, 'Uri\\Rfc3986\\UriBuilder::setFragment() fragment', 0);
        }
        VmUri::builderApply($this->receiver($frame), ['fragment' => $fragment]);
        $this->returnSelf($frame);
    }
}

final class Rfc3986UriBuilderBuild extends Rfc3986UriBuilderMethod
{
    public function __construct()
    {
        parent::__construct('build');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Uri\\Rfc3986\\UriBuilder::build() requires VM context');
        $builder = $this->receiver($frame);
        $base = null;
        if (\count($frame->calledArgs) >= 2) {
            $arg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                if (Variable::TYPE_OBJECT !== $arg->type) {
                    throw new \TypeError(
                        'Uri\\Rfc3986\\UriBuilder::build(): Argument #1 ($baseUrl) must be of type ?Uri\\Rfc3986\\Uri'
                    );
                }
                $base = $arg->toObject();
            }
        }
        $uri = VmUri::builderBuild($ctx, $builder, $base);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($uri): void {
            $ret->copyFrom($uri);
        });
    }
}

/** Uri\WhatWg\Url::parse() — VM (#9051). */
final class WhatWgUrlParse extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('parse');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Uri\\WhatWg\\Url::parse() requires VM context');
        if (\count($frame->calledArgs) < 1) {
            throw new \ArgumentCountError('Uri\\WhatWg\\Url::parse() expects at least 1 argument, 0 given');
        }
        $uri = VmReflection::stringArg($frame->calledArgs[0], 'Uri\\WhatWg\\Url::parse() uri', 0);
        $parsed = VmUri::tryParseWhatWg($ctx, $uri);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($parsed): void {
            if (null === $parsed) {
                $ret->null();
            } else {
                $ret->copyFrom($parsed);
            }
        });
    }
}

abstract class WhatWgUrlGetter extends VmClassMethod
{
    public function __construct(string $name)
    {
        parent::__construct($name);
    }

    protected function receiverState(Frame $frame): array
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($this->getName().'() called without $this');
        }
        $self = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $self->type) {
            throw new \LogicException($this->getName().'() called without $this');
        }

        return VmUri::whatWgState($self->toObject());
    }
}

final class WhatWgUrlGetScheme extends WhatWgUrlGetter
{
    public function __construct()
    {
        parent::__construct('getScheme');
    }

    public function execute(Frame $frame): void
    {
        $scheme = (string) ($this->receiverState($frame)['scheme'] ?? '');
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($scheme): void {
            $ret->string($scheme);
        });
    }
}

final class WhatWgUrlGetAsciiHost extends WhatWgUrlGetter
{
    public function __construct()
    {
        parent::__construct('getAsciiHost');
    }

    public function execute(Frame $frame): void
    {
        $host = $this->receiverState($frame)['host'] ?? null;
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($host): void {
            if (null === $host) {
                $ret->null();
            } else {
                $ret->string($host);
            }
        });
    }
}

final class WhatWgUrlGetPath extends WhatWgUrlGetter
{
    public function __construct()
    {
        parent::__construct('getPath');
    }

    public function execute(Frame $frame): void
    {
        $path = (string) ($this->receiverState($frame)['path'] ?? '');
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($path): void {
            $ret->string($path);
        });
    }
}

final class WhatWgUrlToAsciiString extends WhatWgUrlGetter
{
    public function __construct()
    {
        parent::__construct('toAsciiString');
    }

    public function execute(Frame $frame): void
    {
        $uri = VmUri::composeUrlString($this->receiverState($frame), true);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($uri): void {
            $ret->string($uri);
        });
    }
}

final class WhatWgUrlToUnicodeString extends WhatWgUrlGetter
{
    public function __construct()
    {
        parent::__construct('toUnicodeString');
    }

    public function execute(Frame $frame): void
    {
        // ASCII hosts: unicode form matches ascii (#20541 MVP).
        $uri = VmUri::composeUrlString($this->receiverState($frame), true);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($uri): void {
            $ret->string($uri);
        });
    }
}

final class WhatWgUrlGetQuery extends WhatWgUrlGetter
{
    public function __construct()
    {
        parent::__construct('getQuery');
    }

    public function execute(Frame $frame): void
    {
        $query = $this->receiverState($frame)['query'] ?? null;
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($query): void {
            if (null === $query) {
                $ret->null();
            } else {
                $ret->string($query);
            }
        });
    }
}

final class WhatWgUrlGetFragment extends WhatWgUrlGetter
{
    public function __construct()
    {
        parent::__construct('getFragment');
    }

    public function execute(Frame $frame): void
    {
        $fragment = $this->receiverState($frame)['fragment'] ?? null;
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($fragment): void {
            if (null === $fragment) {
                $ret->null();
            } else {
                $ret->string($fragment);
            }
        });
    }
}

final class WhatWgUrlGetPort extends WhatWgUrlGetter
{
    public function __construct()
    {
        parent::__construct('getPort');
    }

    public function execute(Frame $frame): void
    {
        $port = $this->receiverState($frame)['port'] ?? null;
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($port): void {
            if (null === $port) {
                $ret->null();
            } else {
                $ret->int((int) $port);
            }
        });
    }
}

final class WhatWgUrlGetUsername extends WhatWgUrlGetter
{
    public function __construct()
    {
        parent::__construct('getUsername');
    }

    public function execute(Frame $frame): void
    {
        $user = $this->receiverState($frame)['username'] ?? null;
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($user): void {
            if (null === $user) {
                $ret->null();
            } else {
                $ret->string($user);
            }
        });
    }
}

final class WhatWgUrlGetPassword extends WhatWgUrlGetter
{
    public function __construct()
    {
        parent::__construct('getPassword');
    }

    public function execute(Frame $frame): void
    {
        $pass = $this->receiverState($frame)['password'] ?? null;
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($pass): void {
            if (null === $pass) {
                $ret->null();
            } else {
                $ret->string($pass);
            }
        });
    }
}

final class WhatWgUrlGetUnicodeHost extends WhatWgUrlGetter
{
    public function __construct()
    {
        parent::__construct('getUnicodeHost');
    }

    public function execute(Frame $frame): void
    {
        // ASCII domain: unicode host equals ascii host (#20541 MVP).
        $host = $this->receiverState($frame)['host'] ?? null;
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($host): void {
            if (null === $host) {
                $ret->null();
            } else {
                $ret->string($host);
            }
        });
    }
}

final class WhatWgUrlEquals extends WhatWgUrlGetter
{
    public function __construct()
    {
        parent::__construct('equals');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\WhatWg\\Url::equals() expects at least 1 argument, 0 given');
        }
        $left = $this->receiverState($frame);
        $otherVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $otherVar->type) {
            throw new \TypeError(
                'Uri\\WhatWg\\Url::equals(): Argument #1 ($url) must be of type Uri\\WhatWg\\Url'
            );
        }
        $otherObj = $otherVar->toObject();
        if (VmUri::CLASS_WHATWG_URL !== strtolower($otherObj->class->name)) {
            throw new \TypeError(
                'Uri\\WhatWg\\Url::equals(): Argument #1 ($url) must be of type Uri\\WhatWg\\Url, '
                .$otherObj->class->name.' given'
            );
        }
        $right = VmUri::whatWgState($otherObj);
        $includeFragment = false;
        if (\count($frame->calledArgs) >= 3) {
            $mode = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_OBJECT === $mode->type) {
                $includeFragment = 'IncludeFragment' === ($mode->toObject()->enumCaseName ?? '');
            }
        }
        $eq = VmUri::composeUrlString($left, $includeFragment)
            === VmUri::composeUrlString($right, $includeFragment);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($eq): void {
            $ret->bool($eq);
        });
    }
}

final class WhatWgUrlWithQuery extends WhatWgUrlGetter
{
    public function __construct()
    {
        parent::__construct('withQuery');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Uri\\WhatWg\\Url::withQuery() requires VM context');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\WhatWg\\Url::withQuery() expects exactly 1 argument, 0 given');
        }
        $self = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $arg = $frame->calledArgs[1]->resolveIndirect();
        $query = null;
        if (Variable::TYPE_NULL !== $arg->type) {
            $query = VmReflection::stringArg($arg, 'Uri\\WhatWg\\Url::withQuery() query', 0);
        }
        $next = VmUri::whatWgWith($ctx, $self, ['query' => $query]);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($next): void {
            $ret->copyFrom($next);
        });
    }
}

final class WhatWgUrlWithFragment extends WhatWgUrlGetter
{
    public function __construct()
    {
        parent::__construct('withFragment');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Uri\\WhatWg\\Url::withFragment() requires VM context');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\WhatWg\\Url::withFragment() expects exactly 1 argument, 0 given');
        }
        $self = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $arg = $frame->calledArgs[1]->resolveIndirect();
        $fragment = null;
        if (Variable::TYPE_NULL !== $arg->type) {
            $fragment = VmReflection::stringArg($arg, 'Uri\\WhatWg\\Url::withFragment() fragment', 0);
        }
        $next = VmUri::whatWgWith($ctx, $self, ['fragment' => $fragment]);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($next): void {
            $ret->copyFrom($next);
        });
    }
}

final class WhatWgUrlWithScheme extends WhatWgUrlGetter
{
    public function __construct()
    {
        parent::__construct('withScheme');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Uri\\WhatWg\\Url::withScheme() requires VM context');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\WhatWg\\Url::withScheme() expects exactly 1 argument, 0 given');
        }
        $self = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $scheme = VmReflection::stringArg($frame->calledArgs[1], 'Uri\\WhatWg\\Url::withScheme() scheme', 0);
        $next = VmUri::whatWgWith($ctx, $self, ['scheme' => strtolower($scheme)]);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($next): void {
            $ret->copyFrom($next);
        });
    }
}

final class WhatWgUrlWithHost extends WhatWgUrlGetter
{
    public function __construct()
    {
        parent::__construct('withHost');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Uri\\WhatWg\\Url::withHost() requires VM context');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\WhatWg\\Url::withHost() expects exactly 1 argument, 0 given');
        }
        $self = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $arg = $frame->calledArgs[1]->resolveIndirect();
        $host = null;
        if (Variable::TYPE_NULL !== $arg->type) {
            $host = VmReflection::stringArg($arg, 'Uri\\WhatWg\\Url::withHost() host', 0);
        }
        $next = VmUri::whatWgWith($ctx, $self, ['host' => $host]);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($next): void {
            $ret->copyFrom($next);
        });
    }
}

final class WhatWgUrlWithPath extends WhatWgUrlGetter
{
    public function __construct()
    {
        parent::__construct('withPath');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Uri\\WhatWg\\Url::withPath() requires VM context');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\WhatWg\\Url::withPath() expects exactly 1 argument, 0 given');
        }
        $self = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $path = VmReflection::stringArg($frame->calledArgs[1], 'Uri\\WhatWg\\Url::withPath() path', 0);
        $next = VmUri::whatWgWith($ctx, $self, ['path' => $path]);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($next): void {
            $ret->copyFrom($next);
        });
    }
}

final class WhatWgUrlWithPort extends WhatWgUrlGetter
{
    public function __construct()
    {
        parent::__construct('withPort');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Uri\\WhatWg\\Url::withPort() requires VM context');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\WhatWg\\Url::withPort() expects exactly 1 argument, 0 given');
        }
        $self = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $arg = $frame->calledArgs[1]->resolveIndirect();
        $port = null;
        if (Variable::TYPE_NULL !== $arg->type) {
            if (Variable::TYPE_INTEGER === $arg->type) {
                $port = $arg->toInt();
            } elseif (Variable::TYPE_FLOAT === $arg->type) {
                $port = (int) $arg->toFloat();
            } else {
                throw new \TypeError(
                    'Uri\\WhatWg\\Url::withPort(): Argument #1 ($port) must be of type ?int, '
                    .EnumCaseSupport::typeNameForVariable($arg).' given'
                );
            }
        }
        $next = VmUri::whatWgWith($ctx, $self, ['port' => $port]);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($next): void {
            $ret->copyFrom($next);
        });
    }
}

final class WhatWgUrlWithUsername extends WhatWgUrlGetter
{
    public function __construct()
    {
        parent::__construct('withUsername');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Uri\\WhatWg\\Url::withUsername() requires VM context');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\WhatWg\\Url::withUsername() expects exactly 1 argument, 0 given');
        }
        $self = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $arg = $frame->calledArgs[1]->resolveIndirect();
        $username = null;
        if (Variable::TYPE_NULL !== $arg->type) {
            $username = VmReflection::stringArg($arg, 'Uri\\WhatWg\\Url::withUsername() username', 0);
        }
        $next = VmUri::whatWgWith($ctx, $self, ['username' => $username]);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($next): void {
            $ret->copyFrom($next);
        });
    }
}

final class WhatWgUrlWithPassword extends WhatWgUrlGetter
{
    public function __construct()
    {
        parent::__construct('withPassword');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Uri\\WhatWg\\Url::withPassword() requires VM context');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\WhatWg\\Url::withPassword() expects exactly 1 argument, 0 given');
        }
        $self = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $arg = $frame->calledArgs[1]->resolveIndirect();
        $password = null;
        if (Variable::TYPE_NULL !== $arg->type) {
            $password = VmReflection::stringArg($arg, 'Uri\\WhatWg\\Url::withPassword() password', 0);
        }
        $next = VmUri::whatWgWith($ctx, $self, ['password' => $password]);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($next): void {
            $ret->copyFrom($next);
        });
    }
}

final class WhatWgUrlResolve extends WhatWgUrlGetter
{
    public function __construct()
    {
        parent::__construct('resolve');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Uri\\WhatWg\\Url::resolve() requires VM context');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\WhatWg\\Url::resolve() expects at least 1 argument, 0 given');
        }
        $self = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $uri = VmReflection::stringArg($frame->calledArgs[1], 'Uri\\WhatWg\\Url::resolve() uri', 0);
        // Optional &$softErrors ignored in MVP (#20949).
        $next = VmUri::whatWgResolve($ctx, $self, $uri);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($next): void {
            $ret->copyFrom($next);
        });
    }
}

/** Uri\WhatWg\Url::__construct — php-src create_whatwg_uri(is_constructor=true); #21468. */
final class WhatWgUrlConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Uri\\WhatWg\\Url::__construct() requires VM context');
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Uri\\WhatWg\\Url::__construct() called without $this');
        }
        $self = $frame->calledArgs[0]->resolveIndirect()->toObject();
        if (VmUri::hasWhatWgState($self)) {
            throw new \Error('Cannot modify readonly object of class Uri\\WhatWg\\Url');
        }
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\WhatWg\\Url::__construct() expects at least 1 argument, 0 given');
        }
        $uri = VmReflection::stringArg($frame->calledArgs[1], 'Uri\\WhatWg\\Url::__construct() uri', 0);
        $base = null;
        if (\count($frame->calledArgs) >= 3) {
            $baseArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $baseArg->type) {
                if (Variable::TYPE_OBJECT !== $baseArg->type
                    || VmUri::CLASS_WHATWG_URL !== strtolower($baseArg->toObject()->class->name)
                ) {
                    throw new \TypeError(
                        'Uri\\WhatWg\\Url::__construct(): Argument #2 ($baseUrl) must be of type ?Uri\\WhatWg\\Url, '
                        .EnumCaseSupport::typeNameForVariable($baseArg).' given'
                    );
                }
                $base = $baseArg->toObject();
            }
        }

        if (null !== $base) {
            $resolved = VmUri::whatWgResolve($ctx, $base, $uri);
            VmUri::bindWhatWgState($self, VmUri::whatWgState($resolved->toObject()));

            return;
        }

        $parsed = VmUri::tryParseWhatWg($ctx, $uri);
        if (null === $parsed) {
            throw new \Uri\WhatWg\InvalidUrlException('The specified URI is malformed');
        }
        VmUri::bindWhatWgState($self, VmUri::whatWgState($parsed->toObject()));
    }
}

/** Uri\WhatWg\Url::__serialize (#21468). */
final class WhatWgUrlSerialize extends WhatWgUrlGetter
{
    public function __construct()
    {
        parent::__construct('__serialize');
    }

    public function execute(Frame $frame): void
    {
        $uri = VmUri::composeUrlString($this->receiverState($frame), true);
        $inner = new HashTable();
        $uriVar = new Variable(Variable::TYPE_STRING);
        $uriVar->string($uri);
        $inner->addNew('uri', $uriVar);
        $innerVar = new Variable(Variable::TYPE_ARRAY);
        $innerVar->array($inner);
        $props = new HashTable();
        $propsVar = new Variable(Variable::TYPE_ARRAY);
        $propsVar->array($props);
        $outer = new HashTable();
        $outer->addNewIndex(0, $innerVar);
        $outer->addNewIndex(1, $propsVar);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($outer): void {
            $ret->array($outer);
        });
    }
}

/** Uri\WhatWg\Url::__unserialize (#21468). */
final class WhatWgUrlUnserialize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__unserialize');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Uri\\WhatWg\\Url::__unserialize() requires VM context');
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Uri\\WhatWg\\Url::__unserialize() called without $this');
        }
        $self = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $className = $self->class->name;
        if (VmUri::hasWhatWgState($self)) {
            throw new \Error('Cannot modify readonly object of class '.$className);
        }
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Uri\\WhatWg\\Url::__unserialize() expects exactly 1 argument, 0 given');
        }
        $arg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \TypeError(
                'Uri\\WhatWg\\Url::__unserialize(): Argument #1 ($data) must be of type array'
            );
        }
        $pairs = [];
        foreach ($arg->toArray()->iterateKeyed(true) as [$keyVar, $valVar]) {
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $key->type) {
                throw new \Exception('Invalid serialization data for '.$className.' object');
            }
            $pairs[$key->toInt(null)] = $valVar->resolveIndirect();
        }
        if (2 !== \count($pairs) || !isset($pairs[0], $pairs[1])) {
            throw new \Exception('Invalid serialization data for '.$className.' object');
        }
        $stateBag = $pairs[0];
        $propsBag = $pairs[1];
        if (Variable::TYPE_ARRAY !== $stateBag->type || Variable::TYPE_ARRAY !== $propsBag->type) {
            throw new \Exception('Invalid serialization data for '.$className.' object');
        }
        $uriString = null;
        $stateCount = 0;
        foreach ($stateBag->toArray()->iterateKeyed(true) as [$keyVar, $valVar]) {
            ++$stateCount;
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_STRING !== $key->type || 'uri' !== $key->toString(null)) {
                throw new \Exception('Invalid serialization data for '.$className.' object');
            }
            $val = $valVar->resolveIndirect();
            if (Variable::TYPE_STRING !== $val->type) {
                throw new \Exception('Invalid serialization data for '.$className.' object');
            }
            $uriString = $val->toString(null);
        }
        if (1 !== $stateCount || null === $uriString) {
            throw new \Exception('Invalid serialization data for '.$className.' object');
        }
        $propCount = 0;
        foreach ($propsBag->toArray()->iterateKeyed(true) as $_) {
            ++$propCount;
        }
        if ($propCount > 0) {
            throw new \Exception('Invalid serialization data for '.$className.' object');
        }
        $parsed = VmUri::tryParseWhatWg($ctx, $uriString);
        if (null === $parsed) {
            throw new \Exception('Invalid serialization data for '.$className.' object');
        }
        VmUri::bindWhatWgState($self, VmUri::whatWgState($parsed->toObject()));
    }
}

/** Uri\WhatWg\Url::__debugInfo (#21468). */
final class WhatWgUrlDebugInfo extends WhatWgUrlGetter
{
    public function __construct()
    {
        parent::__construct('__debugInfo');
    }

    public function execute(Frame $frame): void
    {
        $info = VmUri::debugInfoFromState($this->receiverState($frame));
        $ht = new HashTable();
        foreach ($info as $name => $value) {
            $slot = VmJson::import($value);
            $ht->addNew((string) $name, $slot);
        }
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ht): void {
            $ret->array($ht);
        });
    }
}

/** Uri\WhatWg\UrlValidationError::__construct (#20949). */
final class WhatWgUrlValidationErrorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext ?? throw new \LogicException('Uri\\WhatWg\\UrlValidationError::__construct() requires VM context');
        if (\count($frame->calledArgs) < 4) {
            throw new \ArgumentCountError(
                'Uri\\WhatWg\\UrlValidationError::__construct() expects exactly 3 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $self = $frame->calledArgs[0]->resolveIndirect()->toObject();
        $context = VmReflection::stringArg($frame->calledArgs[1], 'Uri\\WhatWg\\UrlValidationError::__construct() context', 0);
        $typeVar = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $typeVar->type) {
            throw new \TypeError(
                'Uri\\WhatWg\\UrlValidationError::__construct(): Argument #2 ($type) must be of type Uri\\WhatWg\\UrlValidationErrorType'
            );
        }
        $failureVar = $frame->calledArgs[3]->resolveIndirect();
        $failure = Variable::TYPE_BOOLEAN === $failureVar->type
            ? $failureVar->toBool()
            : (bool) $failureVar->toInt();

        $self->getProperty('context')->string($context);
        $self->getProperty('type')->copyFrom($typeVar);
        $self->getProperty('failure')->bool($failure);
        $self->constructed = true;
    }
}
