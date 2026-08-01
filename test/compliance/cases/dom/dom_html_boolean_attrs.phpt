--TEST--
Dom\HTMLDocument keeps valueless boolean attrs (WHATWG empty attribute syntax; #26099)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
../../../repro/issue_26099_dom_html_boolean_attrs.php
--EXPECT--
disabled,hidden,id
hidden=true
disabled=true
hidden_val=''
hidden2=''
hidden3='hidden'
