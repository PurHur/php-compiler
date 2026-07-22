--TEST--
SimpleXMLElement::asXML($filename) / saveXML($filename) write file + true (#22006, ext/simplexml/sxe.c)
--FILE--
<?php
$x = simplexml_load_string('<r>t</r>');
$p = sys_get_temp_dir() . '/phpc_asxml_filename_compliance.xml';
@unlink($p);
$r = $x->asXML($p);
echo 'ret=', var_export($r, true), "\n";
echo 'exists=', var_export(file_exists($p), true), "\n";
$body = (string) @file_get_contents($p);
echo 'body=', (str_contains($body, '<r>t</r>') ? 'ok' : 'bad'), "\n";
@unlink($p);

$p2 = sys_get_temp_dir() . '/phpc_savexml_filename_compliance.xml';
@unlink($p2);
$r2 = $x->saveXML($p2);
echo 'save=', var_export($r2, true), ' ', (file_exists($p2) ? 'ok' : 'missing'), "\n";
@unlink($p2);

$s = $x->asXML();
echo 'noarg=', (is_string($s) && str_contains($s, '<r>t</r>') ? 'ok' : 'bad'), "\n";

try {
    $x->asXML('');
    echo "empty=ok\n";
} catch (ValueError $e) {
    echo 'empty=', $e->getMessage(), "\n";
}

$n = $x->asXML(null);
echo 'nullarg=', (is_string($n) && str_contains($n, '<r>t</r>') ? 'ok' : 'bad'), "\n";
?>
--EXPECT--
ret=true
exists=true
body=ok
save=true ok
noarg=ok
empty=Path cannot be empty
nullarg=ok
