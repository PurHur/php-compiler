--TEST--
AOT: MiniWebApp renderHello + $_REQUEST name + layout/hello partial (issues #747, #784, #807) @group miniwebapp-bisect
--ENV--
QUERY_STRING=route=hello&name=Dev
SCRIPT_NAME=/index.php
--RUNFILE--
miniwebapp_render_hello/entry.php
--EXPECT--
Hello Dev
