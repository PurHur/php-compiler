<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

use PHPCompiler\VM\Context;

/**
 * Register LDAP\Connection / Result / ResultEntry (php-src ext/ldap/ldap.stub.php; #3369).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        if (!LdapExtensionPolicy::advertisesClasses()) {
            return;
        }
        VmLdapConnection::registerClass($ctx);
        VmLdapResult::registerClasses($ctx);
    }
}
