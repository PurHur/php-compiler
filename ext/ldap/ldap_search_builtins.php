<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * Phase-1 ldap search/result builtins (php-src ext/ldap/ldap.c; #3369).
 */

abstract class ldap_search_base extends Internal
{
    abstract protected function scope(): int;

    abstract protected function functionName(): string;

    public function execute(Frame $frame): void
    {
        $name = $this->functionName();
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 8) {
            throw new \ArgumentCountError(\sprintf(
                '%s() expects between 3 and 8 arguments, %d given',
                $name,
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], $name, 1);
        $base = VmString::coerceStringBuiltinArg($frame->calledArgs[1], $name, 1, 'base');
        $filter = VmString::coerceStringBuiltinArg($frame->calledArgs[2], $name, 2, 'filter');
        $attributes = [];
        if ($argc >= 4) {
            $attrVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $attrVar->type) {
                throw new \TypeError(\sprintf(
                    '%s(): Argument #4 ($attributes) must be of type array',
                    $name
                ));
            }
            foreach ($attrVar->toArray()->exportKeyValuePairs(true) as [, $elem]) {
                $attributes[] = VmString::coerceStringBuiltinArg($elem, $name, 3, 'attributes');
            }
        }
        $attrsonly = 0;
        if ($argc >= 5) {
            $attrsonly = $frame->calledArgs[4]->resolveIndirect()->toInt();
        }
        $sizelimit = -1;
        if ($argc >= 6) {
            $sizelimit = $frame->calledArgs[5]->resolveIndirect()->toInt();
        }
        $timelimit = -1;
        if ($argc >= 7) {
            $timelimit = $frame->calledArgs[6]->resolveIndirect()->toInt();
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException($name.'() requires a VM context');
        }
        $result = VmLdapCore::search(
            $conn,
            $base,
            $filter,
            $attributes,
            $attrsonly,
            $sizelimit,
            $timelimit,
            $this->scope(),
            $ctx
        );
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->functionName().'() is not implemented for JIT in this compiler build (issue #3369)');
    }
}

final class ldap_search extends ldap_search_base
{
    public function __construct()
    {
        parent::__construct('ldap_search');
    }

    protected function scope(): int
    {
        return VmLdapNative::LDAP_SCOPE_SUBTREE;
    }

    protected function functionName(): string
    {
        return 'ldap_search';
    }
}

final class ldap_list extends ldap_search_base
{
    public function __construct()
    {
        parent::__construct('ldap_list');
    }

    protected function scope(): int
    {
        return VmLdapNative::LDAP_SCOPE_ONELEVEL;
    }

    protected function functionName(): string
    {
        return 'ldap_list';
    }
}

final class ldap_read extends ldap_search_base
{
    public function __construct()
    {
        parent::__construct('ldap_read');
    }

    protected function scope(): int
    {
        return VmLdapNative::LDAP_SCOPE_BASE;
    }

    protected function functionName(): string
    {
        return 'ldap_read';
    }
}

final class ldap_count_entries extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_count_entries');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_count_entries() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_count_entries', 1);
        $result = VmLdapArg::requireResult($frame->calledArgs[1], 'ldap_count_entries', 2);
        $count = VmLdapNative::countEntries(
            VmLdapConnection::native($conn),
            VmLdapResult::resultNative($result)
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($count);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitLdapResult::invokeCountEntries($context, $args);
    }
}

final class ldap_get_entries extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_get_entries');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_get_entries() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_get_entries', 1);
        $result = VmLdapArg::requireResult($frame->calledArgs[1], 'ldap_get_entries', 2);
        $entries = VmLdapCore::getEntries($conn, $result);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $entries) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($entries);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ldap_get_entries() is not implemented for JIT in this compiler build (issue #3369)');
    }
}

final class ldap_first_entry extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_first_entry');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_first_entry() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_first_entry', 1);
        $result = VmLdapArg::requireResult($frame->calledArgs[1], 'ldap_first_entry', 2);
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('ldap_first_entry() requires a VM context');
        }
        $entry = VmLdapNative::firstEntry(
            VmLdapConnection::native($conn),
            VmLdapResult::resultNative($result)
        );
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $entry) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom(VmLdapResult::wrapEntry($entry, $ctx, $conn, $result->id));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ldap_first_entry() is not implemented for JIT in this compiler build (issue #3369)');
    }
}

final class ldap_next_entry extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_next_entry');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_next_entry() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_next_entry', 1);
        $entryObj = VmLdapArg::requireEntry($frame->calledArgs[1], 'ldap_next_entry', 2);
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('ldap_next_entry() requires a VM context');
        }
        $next = VmLdapNative::nextEntry(
            VmLdapConnection::native($conn),
            VmLdapResult::entryNative($entryObj)
        );
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $next) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom(
            VmLdapResult::wrapEntry($next, $ctx, $conn, VmLdapResult::entryResultId($entryObj))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ldap_next_entry() is not implemented for JIT in this compiler build (issue #3369)');
    }
}

/** ldap_count_references() — php-src ext/ldap/ldap.c; #22181. */
final class ldap_count_references extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_count_references');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_count_references() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_count_references', 1);
        $result = VmLdapArg::requireResult($frame->calledArgs[1], 'ldap_count_references', 2);
        $count = VmLdapNative::countReferences(
            VmLdapConnection::native($conn),
            VmLdapResult::resultNative($result)
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($count);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ldap_count_references() is not implemented for JIT in this compiler build (issue #22181)');
    }
}

/** ldap_first_reference() — php-src ext/ldap/ldap.c; #22181. */
final class ldap_first_reference extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_first_reference');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_first_reference() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_first_reference', 1);
        $result = VmLdapArg::requireResult($frame->calledArgs[1], 'ldap_first_reference', 2);
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('ldap_first_reference() requires a VM context');
        }
        $entry = VmLdapNative::firstReference(
            VmLdapConnection::native($conn),
            VmLdapResult::resultNative($result)
        );
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $entry) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom(VmLdapResult::wrapEntry($entry, $ctx, $conn, $result->id));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ldap_first_reference() is not implemented for JIT in this compiler build (issue #22181)');
    }
}

/** ldap_next_reference() — php-src ext/ldap/ldap.c; #22181. */
final class ldap_next_reference extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_next_reference');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_next_reference() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_next_reference', 1);
        $entryObj = VmLdapArg::requireEntry($frame->calledArgs[1], 'ldap_next_reference', 2);
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('ldap_next_reference() requires a VM context');
        }
        $next = VmLdapNative::nextReference(
            VmLdapConnection::native($conn),
            VmLdapResult::entryNative($entryObj)
        );
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $next) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom(
            VmLdapResult::wrapEntry($next, $ctx, $conn, VmLdapResult::entryResultId($entryObj))
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ldap_next_reference() is not implemented for JIT in this compiler build (issue #22181)');
    }
}

/**
 * ldap_parse_reference() — php-src HAVE_LDAP_PARSE_REFERENCE; #22181.
 *
 * Signature: (LDAP\Connection, LDAP\ResultEntry, &$referrals): bool
 */
final class ldap_parse_reference extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_parse_reference');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_parse_reference() expects exactly 3 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_parse_reference', 1);
        $entryObj = VmLdapArg::requireEntry($frame->calledArgs[1], 'ldap_parse_reference', 2);
        $refs = VmLdapNative::parseReference(
            VmLdapConnection::native($conn),
            VmLdapResult::entryNative($entryObj)
        );
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $refs) {
            $frame->returnVar->bool(false);

            return;
        }
        $ht = new HashTable();
        foreach ($refs as $i => $url) {
            $slot = new Variable();
            $slot->string($url);
            $ht->addIndex($i, $slot);
        }
        $frame->calledArgs[2]->resolveIndirect()->array($ht);
        $frame->returnVar->bool(true);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ldap_parse_reference() is not implemented for JIT in this compiler build (issue #22181)');
    }
}

final class ldap_get_attributes extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_get_attributes');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_get_attributes() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $conn = VmLdapArg::requireConnection($frame->calledArgs[0], 'ldap_get_attributes', 1);
        $entry = VmLdapArg::requireEntry($frame->calledArgs[1], 'ldap_get_attributes', 2);
        $attrs = VmLdapCore::getAttributesMap($conn, $entry);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $attrs) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom($attrs);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ldap_get_attributes() is not implemented for JIT in this compiler build (issue #21850)');
    }
}

final class ldap_free_result extends Internal
{
    public function __construct()
    {
        parent::__construct('ldap_free_result');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ldap_free_result() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $result = VmLdapArg::requireResult($frame->calledArgs[0], 'ldap_free_result', 1);
        $ok = VmLdapResult::freeResult($result);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('ldap_free_result() is not implemented for JIT in this compiler build (issue #3369)');
    }
}
