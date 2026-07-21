<?php

declare(strict_types=1);

echo 'set=', function_exists('ldap_set_option') ? 'yes' : 'no', PHP_EOL;
echo 'get=', function_exists('ldap_get_option') ? 'yes' : 'no', PHP_EOL;
