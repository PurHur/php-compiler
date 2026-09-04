<?php
/**
 * PCRE pattern numeric backreferences (`\1`) must match Zend (#36380).
 *
 * VmPregEngine previously treated `\1` as octal chr(1), so Parsedown
 * inlineCode `/^([`]++)…\1…/` never matched under VM.
 */
$cases = [
    ['/(a)\\1/', 'aa', 1, ['aa', 'a']],
    ['/(foo)\\1/', 'foofoo', 1, ['foofoo', 'foo']],
    ['/(foo)\\1/', 'foobar', 0, []],
    ['/^([`]+)(.+?)\\1$/', '`code`', 1, ['`code`', '`', 'code']],
    ['/^([`]++)[ ]*+(.+?)[ ]*+(?<![`])\\1(?!`)/s', '`code span`', 1, ['`code span`', '`', 'code span']],
    // `\0` remains octal NUL, not a backref
    ['/a\\0b/', "a\0b", 1, ["a\0b"]],
    // `\n` is LF, not literal `n`
    ['/a\\nb/', "a\nb", 1, ["a\nb"]],
    ['/a\\nb/', 'anb', 0, []],
];

$fail = 0;
foreach ($cases as [$re, $subj, $expectR, $expectM]) {
    $m = [];
    $r = preg_match($re, $subj, $m);
    if ($r !== $expectR) {
        fwrite(STDERR, "FAIL $re r=$r want=$expectR\n");
        $fail++;
        continue;
    }
    if ($expectR === 1 && $m !== $expectM) {
        fwrite(STDERR, "FAIL $re matches=" . json_encode($m) . " want=" . json_encode($expectM) . "\n");
        $fail++;
        continue;
    }
    echo "OK $re\n";
}
exit($fail === 0 ? 0 : 1);
