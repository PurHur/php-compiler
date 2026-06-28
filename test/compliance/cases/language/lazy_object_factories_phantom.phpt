--TEST--
Language: createLazyGhost/createLazyProxy — not advertised on PHP 8.2 reference profile (#12375)
--FILE--
<?php
$ghost = (int) function_exists('createLazyGhost');
$proxy = (int) function_exists('createLazyProxy');
echo "ghost={$ghost} proxy={$proxy}\n";
--EXPECT--
ghost=0 proxy=0
