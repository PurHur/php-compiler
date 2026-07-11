<?php

declare(strict_types=1);

echo function_exists('ldap_escape') ? "yes\n" : "no\n";
var_dump(ldap_escape('(a=b)', '', LDAP_ESCAPE_FILTER));
var_dump(ldap_escape('cn=admin,ou=people', '', LDAP_ESCAPE_DN));
