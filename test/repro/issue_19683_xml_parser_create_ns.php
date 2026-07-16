<?php
/**
 * Repro for #19683 — xml_parser_create_ns() namespace-expanded element names.
 *
 * Closures are assigned before registration so the handler Variable keeps
 * ClosureState (inline Closure call-args remain #19343).
 */
echo function_exists('xml_parser_create_ns') ? "yes\n" : "no\n";
$p = xml_parser_create_ns();
xml_parser_set_option($p, XML_OPTION_CASE_FOLDING, 0);
$start = function ($p, $n, $a) { echo "S:$n\n"; };
$end = function ($p, $n) { echo "E:$n\n"; };
xml_set_element_handler($p, $start, $end);
xml_parse($p, "<r xmlns:a=\"urn:a\"><a:x/></r>", true);
