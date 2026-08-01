<?php
declare(strict_types=1);

// #23546 — idn empty domain must suffix : U_ILLEGAL_ARGUMENT_ERROR (php-src intl_error_get_message)
@idn_to_ascii('');
echo intl_get_error_code(), '|', intl_get_error_message(), "\n";
