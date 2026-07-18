<?php
declare(strict_types=1);

$dir = sys_get_temp_dir() . '/issue_20403_' . getmypid();
@mkdir($dir);
$part = $dir . '/part.xml';
file_put_contents($part, "hi\n");

$xml = '<r xmlns:xi="http://www.w3.org/2001/XInclude"><xi:include href="' . $part . '" parse="text"/></r>';
$doc = new DOMDocument();
$doc->loadXML($xml);
$n = @$doc->xinclude();
$out = $doc->saveXML();
echo 'n=' . var_export($n, true)
    . ' has=' . (str_contains($out, 'hi') ? 'yes' : 'no')
    . "\n";

@unlink($part);
@rmdir($dir);
