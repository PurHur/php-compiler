# AOT PHPT fixtures (`test/fixtures/aot/cases`)

## MiniWebApp bisect ladder (`@group miniwebapp-bisect`)

Ordered smallest → largest for **#764** triage (issues **#880**, **#879**). Same order as `test/aot/MiniWebAppBisectAotTest.php` and `script/miniwebapp-aot-bisect.sh`.

| Step | Fixture | Tracking |
|------|---------|----------|
| 1 | `isset_object_property_array` | #848 |
| 2 | `require_return_config` | #806 |
| 3 | `nested_include_two_tier` | #878 |
| 4 | `deploy_path_layout_nested` | #878 / #764 |
| 5 | `miniwebapp_render_home` | #867 |
| 6 | `layout_script_base` | #866 |
| 7 | `coalesce_then_inherited_local` | #866 |
| 8 | `coalesce_then_htmlspecialchars` | #866 |
| 9 | `coalesce_scriptbase_htmlspecialchars` | #764 / #866 |
| 10 | `coalesce_then_nested_include` | #784 |
| 11 | `layout_title_branch` | #784 |
| 12 | `layout_partial_chain` | #807 |
| 13 | `method_include_void_array_property` | #846 |

Run (LLVM 9 required):

```bash
./script/ci-local.sh --group miniwebapp-bisect
# or:
vendor/bin/phpunit --group miniwebapp-bisect
./script/miniwebapp-aot-bisect.sh
./script/miniwebapp-aot-bisect.sh --from nested_include_two_tier
```

Optional full **003** CLI execute after the ladder: `MINIWEBAPP_AOT_BISECT_INCLUDE_APP=1 ./script/miniwebapp-aot-bisect.sh`.

## Related fixtures (outside bisect order)

| Fixture | Tracks |
|---------|--------|
| `class_method_json_api` | [#849](https://github.com/PurHur/php-compiler/issues/849) — `Router::renderApiStatus()` JSON + `$this->resolveAppName()` |
| `json_encode_api` | procedural JSON headers |
