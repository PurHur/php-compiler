--TEST--
AOT: miniwebapp renderHome resolveAppName + layout include (#764, issue #867)
--RUNFILE--
miniwebapp_render_home/entry.php
--EXPECT--
<!DOCTYPE html>
<html>
<head>
<title>Home — MiniWebApp</title>
</head>
<body>
<p>MiniWebApp</p>
</body>
</html>
