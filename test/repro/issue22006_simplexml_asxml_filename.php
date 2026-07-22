<?php
// #22006 — SimpleXMLElement::asXML($filename) writes file and returns true
$x = simplexml_load_string('<r>t</r>');
$p = sys_get_temp_dir() . '/phpc_issue22006_asxml.xml';
@unlink($p);
$r = $x->asXML($p);
echo 'ret=', var_export($r, true), "\n";
echo 'exists=', var_export(file_exists($p), true), "\n";
echo 'has_r=', var_export(str_contains((string) @file_get_contents($p), '<r>t</r>'), true), "\n";
$p2 = sys_get_temp_dir() . '/phpc_issue22006_savexml.xml';
@unlink($p2);
$r2 = $x->saveXML($p2);
echo 'save_ret=', var_export($r2, true), "\n";
echo 'save_exists=', var_export(file_exists($p2), true), "\n";
$s = $x->asXML();
echo 'noarg_string=', var_export(is_string($s) && str_contains($s, '<r>t</r>'), true), "\n";
try {
    $x->asXML('');
    echo "empty=ok\n";
} catch (ValueError $e) {
    echo 'empty=', $e->getMessage(), "\n";
}
@unlink($p);
@unlink($p2);
