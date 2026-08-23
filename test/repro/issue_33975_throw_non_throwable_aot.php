<?php
// #33975 — AOT uncaught throw of non-Throwable must Error, not SIGSEGV
// php-src: Zend/zend_exceptions.c
class UncaughtProbe
{
}
throw new UncaughtProbe();
