# AOT PHPT fixtures (`test/fixtures/aot/cases`)

End-to-end compile-and-run cases consumed by `test/aot/AotTest.php` (`@group aot-link`).

## MiniWebApp #764 bisect ladder

Ordered smallest → largest for native execute debugging ([#764](https://github.com/PurHur/php-compiler/issues/764)):

| Step | PHPT | Tracking |
|------|------|----------|
| 1 | `isset_object_property_array` | [#848](https://github.com/PurHur/php-compiler/issues/848) |
| 2 | `require_return_config` | [#806](https://github.com/PurHur/php-compiler/issues/806) |
| 3 | `nested_include_two_tier` | [#878](https://github.com/PurHur/php-compiler/issues/878) |
| 4 | `miniwebapp_render_home` | [#867](https://github.com/PurHur/php-compiler/issues/867) |
| 5 | `layout_script_base` | [#866](https://github.com/PurHur/php-compiler/issues/866) |
| 6 | `layout_title_branch` | [#784](https://github.com/PurHur/php-compiler/issues/784), [#832](https://github.com/PurHur/php-compiler/issues/832) |
| 7 | `method_include_void_array_property` | [#846](https://github.com/PurHur/php-compiler/issues/846) |

Run only this ladder (LLVM required):

```bash
./script/ci-local.sh --group miniwebapp-bisect
vendor/bin/phpunit --group miniwebapp-bisect test/aot/AotTest.php
./script/miniwebapp-aot-bisect.sh
make miniwebapp-aot-bisect
```

Manifest: `AotTest::MINIWEBAPP_BISECT_CASES` and `testMiniWebAppBisectCases` ([#880](https://github.com/PurHur/php-compiler/issues/880)).
