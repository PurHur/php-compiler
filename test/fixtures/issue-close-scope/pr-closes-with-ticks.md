## Summary

Closes #36400 with every Done-when box ticked.

## Done when (from #36400)

- [x] Template + gate live; a test PR that says "Closes #N" without the ticked list is rejected by the gate
- [x] First weekly audit posted; every `needs-respin` from it has a fresh child issue

## Test plan

- [x] `php script/check-issue-close-scope.php --self-test`
