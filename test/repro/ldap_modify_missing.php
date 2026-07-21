<?php

declare(strict_types=1);

foreach (['ldap_mod_add', 'ldap_mod_replace', 'ldap_mod_del', 'ldap_mod_batch', 'ldap_rename'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'no', PHP_EOL;
}
