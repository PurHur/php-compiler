--TEST--
Nested try-finally without inner catch propagates exception to outer catch (#24728)
--FILE--
<?php
function test_empty_finally(): string {
    try {
        try {
            throw new RuntimeException("inner");
        } finally {
        }
    } catch (RuntimeException $e) {
        return "caught: " . $e->getMessage();
    }
    return "unreachable";
}
echo test_empty_finally() . "\n";

function test_double_nested(): string {
    try {
        try {
            try {
                throw new RuntimeException("deep");
            } finally {}
        } finally {}
    } catch (RuntimeException $e) {
        return "caught: " . $e->getMessage();
    }
    return "unreachable";
}
echo test_double_nested() . "\n";

function test_nonempty_finally(): string {
    try {
        try {
            throw new LogicException("inner2");
        } finally {
            $x = 1;
        }
    } catch (LogicException $e) {
        return "caught: " . $e->getMessage();
    }
    return "unreachable";
}
echo test_nonempty_finally() . "\n";

function test_normal_flow(): string {
    try {
        try {
            $v = "ok";
        } finally {}
    } catch (RuntimeException $e) {
        return "caught";
    }
    return $v;
}
echo test_normal_flow() . "\n";
?>
--EXPECT--
caught: inner
caught: deep
caught: inner2
ok
