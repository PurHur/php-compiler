--TEST--
ReflectionConstant file/ext/doc/inNamespace phantom gate on PROFILE=8.4 (#22662)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$r = new ReflectionConstant('PHP_VERSION');
echo method_exists($r, 'getFileName') ? "file-yes\n" : "file-no\n";
echo method_exists($r, 'getExtension') ? "ext-yes\n" : "ext-no\n";
echo method_exists($r, 'getExtensionName') ? "extName-yes\n" : "extName-no\n";
echo method_exists($r, 'getDocComment') ? "doc-yes\n" : "doc-no\n";
echo method_exists($r, 'inNamespace') ? "inNs-yes\n" : "inNs-no\n";
echo method_exists($r, 'getStartLine') ? "start-yes\n" : "start-no\n";
echo method_exists($r, 'getEndLine') ? "end-yes\n" : "end-no\n";
--EXPECT--
file-no
ext-no
extName-no
doc-no
inNs-no
start-no
end-no
