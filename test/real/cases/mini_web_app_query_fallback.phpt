--TEST--
mini_web_app: home route via QUERY_STRING route= fallback
--ENV--
REQUEST_METHOD=GET
--GET--
route=home
--RUNFILE--
../../../examples/003-MiniWebApp/public/index.php
--EXPECTREGEX--
MiniWebApp
