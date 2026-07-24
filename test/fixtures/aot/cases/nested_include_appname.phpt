--TEST--
AOT: nested layout→partial keeps $appName (not $title) (#22845) @group miniwebapp-bisect
--RUNFILE--
nested_include_appname/entry.php
--EXPECT--
TITLE=Home|APP=MiniWebApp
H1=MiniWebApp
