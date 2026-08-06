<?php
/**
 * #28171 — XML_OPTION_PARSE_HUGE PROFILE≥8.4 set/get; absent ≤8.3.
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28171_xml_option_parse_huge.php
 */
echo 'HUGE=', defined('XML_OPTION_PARSE_HUGE') ? (string) constant('XML_OPTION_PARSE_HUGE') : 'UNDEF', PHP_EOL;
if (!defined('XML_OPTION_PARSE_HUGE')) {
    $p = xml_parser_create();
    try {
        xml_parser_set_option($p, 5, 1);
        echo "set5=ok\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), PHP_EOL;
    }
} else {
    $p = xml_parser_create();
    var_export(xml_parser_set_option($p, XML_OPTION_PARSE_HUGE, true));
    echo PHP_EOL;
    var_export(xml_parser_get_option($p, XML_OPTION_PARSE_HUGE));
    echo PHP_EOL;
}
