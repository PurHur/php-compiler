# PHPUnit test layout

## MiniWebApp CGI env (`test/support/MiniWebAppCgiEnv.php`)

Shared CGI env contract for `examples/003-MiniWebApp` VM/AOT/shell smokes (issue [#790](https://github.com/PurHur/php-compiler/issues/790)).

PHPUnit gates (`MiniWebAppVmCliTest`, `MiniWebAppAotExecuteTest`, etc.) call `MiniWebAppCgiEnv::queryRouteHome()` and related scenario helpers instead of duplicating `QUERY_STRING` / `REQUEST_METHOD` keys.

Shell scripts can emit the same mapping:

```bash
./script/miniwebapp-cgi-env.php --list
./script/miniwebapp-cgi-env.php --json shellQueryRouteHome
eval "$(./script/miniwebapp-cgi-env.php --export shellQueryRouteHome)"
```

See `test/fixtures/cgi-env/miniwebapp-home.env` for the home-route fixture aligned with `shellQueryRouteHome()`.
