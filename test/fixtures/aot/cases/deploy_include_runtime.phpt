--TEST--
AOT: phpc_deploy_path() resolves template under PHPC_DEPLOY_ROOT at runtime (#623)
--ENV--
PHPC_DEPLOY_ROOT=/compiler/test/fixtures/aot/cases/deploy_include_template/deploy
--RUNFILE--
deploy_include_template/entry.php
--EXPECT--
deploy-tree
--EXPECT_EXIT--
0
