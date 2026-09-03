# Pull request template (#36400)

## Summary

<!-- What changed and why. One to three bullets. -->

## Closing scope

<!--
Definition of Done (#36400):
- Use **Closes #N** only when every Done-when box from issue N is ticked below
  with the **exact** checkbox text from the issue, plus the gate/command output.
- Use **Part of #N** for partial work and list what remains.
- Issues without a ## Done when list must get one before being claimed.
-->

- Closes? / Part of? : <!-- e.g. Part of #36400  OR  Closes #36400 -->

### Done when (copy from the issue; tick only what this PR completes)

- [ ] <!-- paste each Done-when line from the issue; mark [x] when done -->

### What remains (required for Part of #N)

<!-- Bullet list, or "none — closing". -->

## Test plan

- [ ] Named gates run in the pinned Docker image (`./script/docker-exec.sh` / `./script/phpunit.sh`)
- [ ] `php script/check-issue-close-scope.php --pr-body <this-body-file> --repo PurHur/php-compiler` (when using Closes #N)
- [ ] Repro / Done-when commands pasted with output
