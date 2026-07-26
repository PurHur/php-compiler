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
            if (\is_string($value)) {
                $var->string($value);
            } else {
                $var->int($value);
            }
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
        require_once __DIR__.'/ldap_exop_builtins.php';
        require_once __DIR__.'/ldap_modify_builtins.php';
        require_once __DIR__.'/ldap_dn_builtins.php';
        require_once __DIR__.'/ldap_result_builtins.php';

        $fns = [
            new ldap_escape(),
            new ldap_dn2ufn(),
            new ldap_explode_dn(),
            new ldap_connect(),
            new ldap_bind(),
            new ldap_bind_ext(),
            new ldap_unbind(),
            new ldap_close(),
            new ldap_errno(),
            new ldap_error(),
            new ldap_err2str(),
            new ldap_set_option(),
            new ldap_get_option(),
            new ldap_start_tls(),
            new ldap_search(),
            new ldap_list(),
            new ldap_read(),
            new ldap_count_entries(),
            new ldap_get_entries(),
            new ldap_first_entry(),
            new ldap_next_entry(),
            new ldap_count_references(),
            new ldap_first_reference(),
            new ldap_next_reference(),
            new ldap_parse_reference(),
            new ldap_get_attributes(),
            new ldap_free_result(),
            new ldap_compare(),
            new ldap_parse_result(),
            new ldap_get_dn(),
            new ldap_first_attribute(),
            new ldap_next_attribute(),
            new ldap_get_values(),
            new ldap_get_values_len(),
            new ldap_exop(),
            new ldap_exop_sync(),
            new ldap_parse_exop(),
            new ldap_exop_whoami(),
            new ldap_exop_refresh(),
            new ldap_exop_passwd(),
            new ldap_mod_add(),
            new ldap_mod_replace(),
            new ldap_mod_del(),
            new ldap_modify(),
            new ldap_add(),
            new ldap_delete(),
            new ldap_modify_batch(),
            new ldap_mod_batch(),
            new ldap_add_ext(),
            new ldap_delete_ext(),
            new ldap_rename_ext(),
            new ldap_mod_add_ext(),
            new ldap_mod_del_ext(),
            new ldap_mod_replace_ext(),
            new ldap_rename(),
        ];
        if (LdapExtensionPolicy::advertisesWalletConnect()) {
            require_once __DIR__.'/ldap_connect_wallet.php';
            $fns[] = new ldap_connect_wallet();
        }

        return $fns;
    }
}
