--TEST--
AOT: deploy-path layout with nested literal partial (MiniWebApp #764, #878) @group miniwebapp-bisect
--ENV--
SCRIPT_NAME=/index.php
--RUNFILE--
deploy_path_layout_nested/entry.php
--EXPECT--
<title>Home — MiniWebApp</title>
<p>MiniWebApp</p>
