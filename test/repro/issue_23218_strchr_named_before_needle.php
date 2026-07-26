<?php
// Repro #23218 — strchr haystack:/needle:/before_needle: named parameters (VM + AOT)
// Reflection names are guarded by compliance .phpt (VM); AOT may lack ReflectionFunction APIs.
$ok = 'abc' === strchr(haystack: 'abcdef', needle: 'd', before_needle: true)
    && 'def' === strchr(haystack: 'abcdef', needle: 'd')
    && 'abc' === strchr('abcdef', 'd', true);
echo $ok ? "ok\n" : "fail\n";
