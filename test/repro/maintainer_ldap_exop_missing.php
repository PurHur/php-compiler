<?php

declare(strict_types=1);

foreach (['ldap_exop', 'ldap_exop_sync', 'ldap_parse_exop', 'ldap_exop_refresh'] as $f) {
    echo $f, ': ', function_exists($f) ? 'yes' : 'no', PHP_EOL;
}
