--TEST--
Language: createLazyGhost/createLazyProxy free functions — never advertised (#12375, #28414)
--FILE--
<?php
$ghost = (int) function_exists('createLazyGhost');
$proxy = (int) function_exists('createLazyProxy');
$ghostLc = (int) function_exists('createlazyghost');
$proxyLc = (int) function_exists('createlazyproxy');
echo "ghost={$ghost} proxy={$proxy} ghostLc={$ghostLc} proxyLc={$proxyLc}\n";
--EXPECT--
ghost=0 proxy=0 ghostLc=0 proxyLc=0
