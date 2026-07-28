<?php
/** Historical #6450 helper — superseded by #24222 phantom gate (absent from php-src). */
echo function_exists('get_declared_attributes') ? "fail\n" : "ok\n";
