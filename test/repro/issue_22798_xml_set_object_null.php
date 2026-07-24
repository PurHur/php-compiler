<?php
/** Repro #22798 — xml_set_object(null) TypeError; prior object handlers stay bound. */
class H {
    public array $e = [];
    function s($p, $n, $a) { $this->e[] = $n; }
    function e($p, $n) {}
}
$h = new H();
$p = xml_parser_create();
xml_set_object($p, $h);
xml_set_element_handler($p, 's', 'e');
try {
    xml_set_object($p, null);
    echo "null_ok\n";
} catch (Throwable $ex) {
    echo get_class($ex), ':', $ex->getMessage(), "\n";
}
xml_parse($p, '<r/>', true);
echo 'els=', implode(',', $h->e), "\n";
