<?php
// #34868 — AOT: arrow fn()=>throw returned from static method must catch, not SIGSEGV
// php-src: Zend/zend_compile.c (arrow / throw expression), Zend/zend_exceptions.c
class C
{
    public static function m()
    {
        return fn () => throw new Exception('s');
    }
}
try {
    (C::m())();
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
