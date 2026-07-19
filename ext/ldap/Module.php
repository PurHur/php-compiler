<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * ldap extension module entry (php-src ext/ldap/ldap.c; #6352 / #3369).
 *
 * Requires libldap via FFI — see VmLdapNative. Advertisement follows
 * {@see LdapExtensionPolicy::advertisesExtension()}.
 */
class Module extends ModuleAbstract
{
    public function getExtensionVersion(): string
    {
        return '8.2.0';
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        if (!LdapExtensionPolicy::advertisesBuiltins()) {
            return;
        }
        foreach (LdapConstants::registeredConstants() as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
        if (LdapExtensionPolicy::advertisesClasses()) {
            BuiltinClasses::register($runtime->vmContext);
        }
    }

    public function getFunctions(): array
    {
        if (!LdapExtensionPolicy::advertisesBuiltins()) {
            return [];
        }

        require_once __DIR__.'/ldap_link_builtins.php';
        require_once __DIR__.'/ldap_search_builtins.php';

        return [
            new ldap_escape(),
            new ldap_connect(),
            new ldap_bind(),
            new ldap_unbind(),
            new ldap_close(),
            new ldap_errno(),
            new ldap_error(),
            new ldap_err2str(),
            new ldap_set_option(),
            new ldap_search(),
            new ldap_list(),
            new ldap_read(),
            new ldap_count_entries(),
            new ldap_get_entries(),
            new ldap_first_entry(),
            new ldap_next_entry(),
            new ldap_free_result(),
        ];
    }
}
