<?php
/**
 * Regression: AOT property hooks get/set — empty output (#24682).
 *
 * PROPERTY_FETCH_WRITE for a hooked property must produce a lvalue with
 * objectPropertySlot so the ASSIGN op can dispatch emitSetHookIfNeeded.
 * Before the fix, tryEmitPropertyGet consumed the result as an rvalue
 * even on the write path, and emitWriteOnlyVirtualReadGuard broke out
 * of the fetch entirely for set-only virtual properties.
 *
 * VM84/JIT84: HI
 * AOT84 before fix: (empty)
 */
class C {
    private string $store = "";
    public string $name {
        get => $this->store;
        set(string $v) { $this->store = strtoupper($v); }
    }
}
$o = new C;
$o->name = "hi";
echo $o->name;
