--TEST--
Language: Expr_MagicScriptConst __DIR__/__FILE__/__LINE__ compile lowering (#9848, zend_compile.c)
--RUNFILE--
magic_script_const/run.php
--EXPECTF--
string
string
%S
%S
%d
