--TEST--
Issue #32104 — php_strip_whitespace/highlight_file honor allow_url_include for data://
--FILE--
<?php
$uri = 'data://text/plain,<?php echo 1; //c';
echo "strip=", var_export(php_strip_whitespace($uri), true), "\n";
ob_start();
$hf = highlight_file($uri);
$out = ob_get_clean();
echo "highlight_return=", var_export($hf, true), "\n";
echo "highlight_out_len=", strlen($out), "\n";
echo "fgc=", var_export(file_get_contents($uri), true), "\n";
?>
--EXPECT--
Warning: php_strip_whitespace(): data:// wrapper is disabled in the server configuration by allow_url_include=0 in %s on line %d
Warning: php_strip_whitespace(data://text/plain,<?php echo 1; //c): Failed to open stream: no suitable wrapper could be found in %s on line %d
strip=''
Warning: highlight_file(): data:// wrapper is disabled in the server configuration by allow_url_include=0 in %s on line %d
Warning: highlight_file(data://text/plain,<?php echo 1; //c): Failed to open stream: no suitable wrapper could be found in %s on line %d
Warning: highlight_file(): Failed opening 'data://text/plain,<?php echo 1; //c' for highlighting in %s on line %d
highlight_return=false
highlight_out_len=0
fgc='<?php echo 1; //c'
