<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlrpc;

use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ResourceState;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;

/**
 * XML-RPC server resource + dispatch (php-src ext/xmlrpc/xmlrpc-epi-php.c; #27879).
 *
 * Pure PHP method map — no xmlrpc-epi C engine / runtime/*.c.
 */
final class VmXmlrpcServer
{
    public const RESOURCE_KIND = 'xmlrpc server';

    private static int $nextId = 0;

    /**
     * @var array<int, array{
     *   methods: array<string, Variable>,
     *   introspection: list<Variable>,
     *   introspection_data: list<mixed>
     * }>
     */
    private static array $servers = [];

    public static function create(): int
    {
        $id = ++self::$nextId;
        self::$servers[$id] = [
            'methods' => [],
            'introspection' => [],
            'introspection_data' => [],
        ];

        return $id;
    }

    public static function isValidHandle(int $handle): bool
    {
        return isset(self::$servers[$handle]);
    }

    public static function destroy(int $handle, ?Variable $serverVar = null): bool
    {
        if (!self::isValidHandle($handle)) {
            return false;
        }
        unset(self::$servers[$handle]);
        if (null !== $serverVar) {
            $state = ResourceSupport::stateFromVariable($serverVar);
            if (null !== $state && ResourceState::KIND_XMLRPC_SERVER === $state->kind) {
                $state->handle = 0;
            }
        }

        return true;
    }

    public static function registerMethod(int $handle, string $methodName, Variable $callback): bool
    {
        if (!self::isValidHandle($handle) || '' === $methodName) {
            return false;
        }
        $copy = new Variable();
        $copy->copyFrom($callback->resolveIndirect());
        self::$servers[$handle]['methods'][$methodName] = $copy;

        return true;
    }

    public static function registerIntrospectionCallback(int $handle, Variable $callback): bool
    {
        if (!self::isValidHandle($handle)) {
            return false;
        }
        $copy = new Variable();
        $copy->copyFrom($callback->resolveIndirect());
        self::$servers[$handle]['introspection'][] = $copy;

        return true;
    }

    /**
     * @param mixed $desc
     */
    public static function addIntrospectionData(int $handle, $desc): int
    {
        if (!self::isValidHandle($handle)) {
            return 0;
        }
        self::$servers[$handle]['introspection_data'][] = $desc;

        return 1;
    }

    /**
     * xmlrpc_server_call_method() — decode request, invoke PHP callable, encode response (#27879).
     *
     * Default output is methodResponse XML (php-src b_php_out=false).
     */
    public static function callMethod(
        Frame $frame,
        int $handle,
        string $xml,
        Variable $userData,
        bool $phpOut = false
    ): Variable|false {
        if (!self::isValidHandle($handle)) {
            return false;
        }
        $method = '';
        $params = VmXmlrpc::decodeRequest($xml, $method);
        if (false === $params) {
            return false;
        }
        if (!isset(self::$servers[$handle]['methods'][$method])) {
            return false;
        }
        $callback = self::$servers[$handle]['methods'][$method];
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('xmlrpc_server_call_method() requires an active VM context');
        }

        $methodVar = new Variable();
        $methodVar->string($method);
        $paramsVar = VmJson::import(\is_array($params) ? $params : [$params]);
        $result = VmCallable::invokeAsWithScope(
            'xmlrpc_server_call_method',
            $ctx,
            $frame,
            $callback,
            $methodVar,
            $paramsVar,
            $userData
        );

        if ($phpOut) {
            return $result;
        }

        $response = new Variable();
        $response->string(VmXmlrpc::encodeRequest(null, $result));

        return $response;
    }

    /**
     * Minimal xmlrpc_parse_method_descriptions() — decode XML-RPC value payload to array (#27879).
     *
     * @return array<mixed>|false
     */
    public static function parseMethodDescriptions(string $xml)
    {
        $xml = trim($xml);
        if ('' === $xml) {
            return false;
        }
        $decoded = VmXmlrpc::decode($xml);
        if (false === $decoded) {
            // Accept raw methodResponse/list-shaped documents via decodeRequest.
            $method = '';
            $decoded = VmXmlrpc::decodeRequest($xml, $method);
        }
        if (false === $decoded) {
            return false;
        }
        if (!\is_array($decoded)) {
            return [$decoded];
        }

        return $decoded;
    }

    public static function wrapResource(Variable $dest, int $handle, Context $ctx): void
    {
        ResourceSupport::wrap($dest, $handle, ResourceState::KIND_XMLRPC_SERVER, $ctx);
    }

    public static function handleFromVariable(Variable $var): ?int
    {
        $state = ResourceSupport::stateFromVariable($var);
        if (null === $state || ResourceState::KIND_XMLRPC_SERVER !== $state->kind || $state->handle <= 0) {
            return null;
        }
        if (!self::isValidHandle($state->handle)) {
            return null;
        }

        return $state->handle;
    }
}
