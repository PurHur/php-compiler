<?php
// #23641: AOT registered 1 of 9 Exception members and no __construct, so every
// accessor returned a default. getCode() did not exist at build time at all.
$e = new LogicException("msg here", 42);
echo "msg=", $e->getMessage(), "\n";
echo "code=", $e->getCode(), "\n";
class MyEx extends Exception {}
$m = new MyEx("subclass msg");
echo "submsg=", $m->getMessage(), "\n";
