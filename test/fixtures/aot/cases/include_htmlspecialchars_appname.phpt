--TEST--
AOT: htmlspecialchars() on include-bound method string (#22845)
--RUNFILE--
include_htmlspecialchars_appname/entry.php
--EXPECT--
ECHO=MiniWebApp
HTML=MiniWebApp
LIT=MiniWebApp
