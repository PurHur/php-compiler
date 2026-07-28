<?php
function f(): string {
    try { throw new RuntimeException("x"); }
    catch (RuntimeException $e) { return "caught"; }
    finally { echo "fin "; }
}
echo f(), "\n";
