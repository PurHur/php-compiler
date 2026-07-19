<?php
// Repro #20872 — intl_error_name(UErrorCode) via ICU u_errorName
echo 'exists=', function_exists('intl_error_name') ? 'yes' : 'no', "\n";
if (function_exists('intl_error_name')) {
    echo 'name0=', intl_error_name(0), "\n";
    echo 'name1=', intl_error_name(1), "\n";
    echo 'name_fb=', intl_error_name(-128), "\n";
}
