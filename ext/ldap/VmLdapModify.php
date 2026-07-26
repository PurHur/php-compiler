<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * ldap_mod_* / ldap_rename helpers (php-src ext/ldap/ldap.c; #21853).
 */
final class VmLdapModify
{
    /**
     * @return list<array{op: int, attr: string, values: list<string>|null}>|false
     */
    public static function entryArrayToMods(Variable $entryVar, int $oper, string $functionName): array|false
    {
        $entryVar = $entryVar->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $entryVar->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #3 ($entry) must be of type array',
                $functionName
            ));
        }
        $ht = $entryVar->toArray();
        $mods = [];
        foreach ($ht->exportKeyValuePairs(true) as [$key, $valueVar]) {
            if (!\is_string($key) || '' === $key) {
                @\trigger_error(\sprintf(
                    '%s(): Argument #3 ($entry) must be an associative array of attribute => values',
                    $functionName
                ), \E_USER_WARNING);

                return false;
            }
            if (str_contains($key, "\0")) {
                @\trigger_error(\sprintf(
                    '%s(): Argument #3 ($entry) key must not contain any null bytes',
                    $functionName
                ), \E_USER_WARNING);

                return false;
            }
            $valueVar = $valueVar->resolveIndirect();
            $values = self::coerceAttributeValues($valueVar, $key, $oper, $functionName);
            if (false === $values) {
                return false;
            }
            $mods[] = ['op' => $oper, 'attr' => $key, 'values' => $values];
        }

        return $mods;
    }

    /**
     * @return list<string>|null|false null = remove all (batch only)
     */
    private static function coerceAttributeValues(
        Variable $valueVar,
        string $attr,
        int $oper,
        string $functionName
    ): array|null|false {
        if (Variable::TYPE_ARRAY !== $valueVar->type) {
            $str = VmString::coerceStringBuiltinArg($valueVar, $functionName, 2, 'entry');

            return [$str];
        }
        $ht = $valueVar->toArray();
        $pairs = $ht->exportKeyValuePairs(true);
        if ([] === $pairs) {
            if (LdapConstants::LDAP_MOD_ADD === $oper) {
                @\trigger_error(\sprintf(
                    '%s(): Argument #3 ($entry) attribute "%s" must be a non-empty list of attribute values',
                    $functionName,
                    $attr
                ), \E_USER_WARNING);

                return false;
            }

            return [];
        }
        if (!$ht->isPackedList()) {
            @\trigger_error(\sprintf(
                '%s(): Argument #3 ($entry) attribute "%s" must be an array of attribute values with numeric keys',
                $functionName,
                $attr
            ), \E_USER_WARNING);

            return false;
        }
        $out = [];
        foreach ($pairs as [, $elem]) {
            $out[] = VmString::coerceStringBuiltinArg($elem, $functionName, 2, 'entry');
        }

        return $out;
    }

    /**
     * @return list<array{op: int, attr: string, values: list<string>|null}>|false
     */
    public static function batchToMods(Variable $batchVar, string $functionName): array|false
    {
        $batchVar = $batchVar->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $batchVar->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #3 ($modifications) must be of type array',
                $functionName
            ));
        }
        $ht = $batchVar->toArray();
        if (0 === $ht->getNumElements()) {
            throw new \ValueError(\sprintf('%s(): Argument #3 ($modifications) must not be empty', $functionName));
        }
        if (!$ht->isPackedList()) {
            throw new \ValueError(\sprintf(
                '%s(): Argument #3 ($modifications) must be an array with numeric keys',
                $functionName
            ));
        }
        $mods = [];
        foreach ($ht->exportKeyValuePairs(true) as [, $rowVar]) {
            $rowVar = $rowVar->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $rowVar->type) {
                throw new \TypeError(\sprintf(
                    '%s(): Argument #3 ($modifications) must only contain arrays',
                    $functionName
                ));
            }
            $row = $rowVar->toArray();
            $spec = self::parseBatchRow($row, $functionName);
            if (false === $spec) {
                return false;
            }
            $mods[] = $spec;
        }

        return $mods;
    }

    /**
     * @return array{op: int, attr: string, values: list<string>|null}|false
     */
    private static function parseBatchRow(HashTable $row, string $functionName): array|false
    {
        $attrib = self::rowString($row, LdapConstants::LDAP_MODIFY_BATCH_ATTRIB, $functionName);
        $modtypeVar = $row->find(LdapConstants::LDAP_MODIFY_BATCH_MODTYPE);
        if (null === $modtypeVar || Variable::TYPE_INTEGER !== $modtypeVar->resolveIndirect()->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #3 ($modifications) the value for option "%s" must be of type int',
                $functionName,
                LdapConstants::LDAP_MODIFY_BATCH_MODTYPE
            ));
        }
        $modtype = $modtypeVar->resolveIndirect()->toInt();
        $oper = match ($modtype) {
            LdapConstants::LDAP_MODIFY_BATCH_ADD => LdapConstants::LDAP_MOD_ADD,
            LdapConstants::LDAP_MODIFY_BATCH_REMOVE, LdapConstants::LDAP_MODIFY_BATCH_REMOVE_ALL => LdapConstants::LDAP_MOD_DELETE,
            LdapConstants::LDAP_MODIFY_BATCH_REPLACE => LdapConstants::LDAP_MOD_REPLACE,
            default => throw new \ValueError(\sprintf(
                '%s(): Argument #3 ($modifications) invalid modtype %d',
                $functionName,
                $modtype
            )),
        };
        if (LdapConstants::LDAP_MODIFY_BATCH_REMOVE_ALL === $modtype) {
            return ['op' => $oper, 'attr' => $attrib, 'values' => null];
        }
        $valuesVar = $row->find(LdapConstants::LDAP_MODIFY_BATCH_VALUES);
        if (null === $valuesVar) {
            throw new \ValueError(\sprintf(
                '%s(): Argument #3 ($modifications) a modification entry must contain the "%s" option',
                $functionName,
                LdapConstants::LDAP_MODIFY_BATCH_VALUES
            ));
        }
        $values = self::coerceAttributeValues($valuesVar, $attrib, $oper, $functionName);
        if (false === $values) {
            return false;
        }

        return ['op' => $oper, 'attr' => $attrib, 'values' => $values];
    }

    private static function rowString(HashTable $row, string $key, string $functionName): string
    {
        $var = $row->find($key);
        if (null === $var) {
            throw new \ValueError(\sprintf(
                '%s(): Argument #3 ($modifications) a modification entry must contain the "%s" option',
                $functionName,
                $key
            ));
        }
        $var = $var->resolveIndirect();
        if (Variable::TYPE_STRING !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #3 ($modifications) the value for option "%s" must be of type string',
                $functionName,
                $key
            ));
        }
        $str = $var->toString();
        if (str_contains($str, "\0")) {
            throw new \ValueError(\sprintf(
                '%s(): Argument #3 ($modifications) the value for option "%s" must not contain null bytes',
                $functionName,
                $key
            ));
        }

        return $str;
    }

    public static function modify(
        ObjectEntry $connection,
        string $dn,
        array $mods,
        string $functionName
    ): bool {
        $ld = VmLdapConnection::native($connection);
        $rc = VmLdapNative::modifyExtSync($ld, $dn, $mods);
        VmLdapConnection::setErrno($connection, $rc);
        if (VmLdapNative::LDAP_SUCCESS !== $rc) {
            @\trigger_error(\sprintf(
                '%s(): Modify: %s',
                $functionName,
                VmLdapNative::err2string($rc)
            ), \E_USER_WARNING);

            return false;
        }

        return true;
    }

    /**
     * Full DN add (php-src ldap_add → ldap_add_ext_s; #22196).
     *
     * @param list<array{op: int, attr: string, values: list<string>|null}> $mods
     */
    public static function add(
        ObjectEntry $connection,
        string $dn,
        array $mods,
        string $functionName
    ): bool {
        $ld = VmLdapConnection::native($connection);
        $rc = VmLdapNative::addExtSync($ld, $dn, $mods);
        VmLdapConnection::setErrno($connection, $rc);
        if (VmLdapNative::LDAP_SUCCESS !== $rc) {
            @\trigger_error(\sprintf(
                '%s(): Add: %s',
                $functionName,
                VmLdapNative::err2string($rc)
            ), \E_USER_WARNING);

            return false;
        }

        return true;
    }

    public static function delete(
        ObjectEntry $connection,
        string $dn,
        string $functionName
    ): bool {
        $ld = VmLdapConnection::native($connection);
        $rc = VmLdapNative::deleteExtSync($ld, $dn);
        VmLdapConnection::setErrno($connection, $rc);
        if (VmLdapNative::LDAP_SUCCESS !== $rc) {
            @\trigger_error(\sprintf(
                '%s(): Delete: %s',
                $functionName,
                VmLdapNative::err2string($rc)
            ), \E_USER_WARNING);

            return false;
        }

        return true;
    }

    /**
     * Async modify/add returning LDAP\Result (php-src *_ext; #22196).
     *
     * @param list<array{op: int, attr: string, values: list<string>|null}> $mods
     * @return Variable|false
     */
    public static function modifyExt(
        ObjectEntry $connection,
        string $dn,
        array $mods,
        bool $fullAdd,
        string $functionName,
        Context $ctx
    ): Variable|false {
        $ld = VmLdapConnection::native($connection);
        $info = VmLdapNative::modifyExtAsync($ld, $dn, $mods, $fullAdd);
        $rc = $info['errno'];
        VmLdapConnection::setErrno($connection, $rc);
        if (null === $info['result']) {
            $verb = $fullAdd ? 'Add' : 'Modify';
            @\trigger_error(\sprintf(
                '%s(): %s: %s',
                $functionName,
                $verb,
                -1 === $rc ? ($verb.' operation failed') : VmLdapNative::err2string($rc)
            ), \E_USER_WARNING);

            return false;
        }

        return VmLdapResult::wrapResult($info['result'], $ctx, $connection);
    }

    /**
     * @return Variable|false
     */
    public static function deleteExt(
        ObjectEntry $connection,
        string $dn,
        string $functionName,
        Context $ctx
    ): Variable|false {
        $ld = VmLdapConnection::native($connection);
        $info = VmLdapNative::deleteExtAsync($ld, $dn);
        $rc = $info['errno'];
        VmLdapConnection::setErrno($connection, $rc);
        if (null === $info['result']) {
            @\trigger_error(\sprintf(
                '%s(): Delete: %s',
                $functionName,
                -1 === $rc ? 'Delete operation failed' : VmLdapNative::err2string($rc)
            ), \E_USER_WARNING);

            return false;
        }

        return VmLdapResult::wrapResult($info['result'], $ctx, $connection);
    }

    /**
     * @return Variable|false
     */
    public static function renameExt(
        ObjectEntry $connection,
        string $dn,
        string $newRdn,
        string $newParent,
        bool $deleteOldRdn,
        string $functionName,
        Context $ctx
    ): Variable|false {
        $ld = VmLdapConnection::native($connection);
        $info = VmLdapNative::renameExtAsync($ld, $dn, $newRdn, $newParent, $deleteOldRdn);
        $rc = $info['errno'];
        VmLdapConnection::setErrno($connection, $rc);
        if (null === $info['result']) {
            @\trigger_error(\sprintf(
                '%s(): Rename: %s',
                $functionName,
                -1 === $rc ? 'Rename operation failed' : VmLdapNative::err2string($rc)
            ), \E_USER_WARNING);

            return false;
        }

        return VmLdapResult::wrapResult($info['result'], $ctx, $connection);
    }

    public static function rename(
        ObjectEntry $connection,
        string $dn,
        string $newRdn,
        string $newParent,
        bool $deleteOldRdn
    ): bool {
        $ld = VmLdapConnection::native($connection);
        $rc = VmLdapNative::renameSync($ld, $dn, $newRdn, $newParent, $deleteOldRdn);
        VmLdapConnection::setErrno($connection, $rc);
        if (VmLdapNative::LDAP_SUCCESS !== $rc) {
            @\trigger_error('ldap_rename(): Rename: '.VmLdapNative::err2string($rc), \E_USER_WARNING);

            return false;
        }

        return true;
    }
}
