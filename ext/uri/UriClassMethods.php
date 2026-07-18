<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uri;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\BuiltinExecute;
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
        $state = $this->receiverState($frame);
        $uri = self::compose($state);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($uri): void {
            $ret->string($uri);
        });
    }

    /**
     * @param array<string, mixed> $state
     */
    private static function compose(array $state): string
    {
        $out = '';
        if (isset($state['scheme']) && \is_string($state['scheme']) && '' !== $state['scheme']) {
            $out .= $state['scheme'].':';
        }
        if (isset($state['host']) && \is_string($state['host']) && '' !== $state['host']) {
            $out .= '//';
            if (isset($state['userinfo']) && \is_string($state['userinfo']) && '' !== $state['userinfo']) {
                $out .= $state['userinfo'].'@';
            }
            $out .= $state['host'];
            if (isset($state['port']) && \is_int($state['port'])) {
                $out .= ':'.$state['port'];
            }
        }
        $out .= (string) ($state['path'] ?? '');
        if (isset($state['query']) && \is_string($state['query']) && '' !== $state['query']) {
            $out .= '?'.$state['query'];
        }
        if (isset($state['fragment']) && \is_string($state['fragment']) && '' !== $state['fragment']) {
            $out .= '#'.$state['fragment'];
        }

        return $out;
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
