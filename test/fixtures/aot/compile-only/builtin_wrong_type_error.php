<?php
// Compile-only (#4178): fread/substr/chr wrong-type operands lower TypeError guards for AOT.
try {
    substr('hello', []);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    chr([]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
