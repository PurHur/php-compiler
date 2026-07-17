<?php
/**
 * VM-only: mb_parse_str() sets mb_http_input() identify (#20015).
 */
$r = [];
mb_parse_str('a=1', $r);
echo var_export(mb_http_input(), true), "\n";
mb_parse_str('', $r);
echo var_export(mb_http_input(), true), "\n";
