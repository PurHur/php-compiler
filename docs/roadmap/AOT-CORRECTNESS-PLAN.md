# AOT correctness — inventory and plan

Written 2026-08-17 from a 10-agent differential hunt (each agent: one language area, 15–25
deterministic probes, AOT output compared against Zend byte-for-byte).

**Read `docs/AGENT-HANDOVER-2026-08-17.md` first.** In particular: none of this was measurable until
#31894, because every AOT binary failed to link. Anything diagnosed against a tree where
`script/aot-smoke.sh` is not 8/8 is measuring the toolchain, not the feature.

## Coverage so far

| area | probes run | mismatches | status |
|---|---|---|---|
| statics | 41 | 21 | reported |
| numerics | 44 | 23 | reported |
| inheritance, closures, exceptions, arrays, strings, generators, references, enums-traits | — | — | hunt still running when this was written |

**85 probes, 44 mismatches — roughly one in two.** These are ordinary PHP constructs, not exotic
corners.

Provenance: only the group marked **[verified]** below was re-run by hand in the pinned image with
exit codes read directly. Everything else is **probe-reported** and should be reproduced before anyone acts on
them. That distinction is the point — do not collapse it.

---

## Group 1 — float → string conversion (probably ONE bug, five crashes)

Every one of these segfaults or aborts on a float. They are almost certainly a single conversion
defect, which makes this the highest value-per-fix item in the inventory.

| probe | Zend | AOT |
|---|---|---|
| `printf("%.1f", 1.5)` | `3` | exit 139 |
| `number_format(1234567.891, 2)` | `1,234,567.89` | exit 139 |
| `json_encode(0.1)` | `0.1` | segfault |
| `serialize(0.1)` | `d:0.1;` | segfault |
| `strval(0.1)` | `"0.1"` | **compiler itself** segfaults (exit 139) |

The last one is notable: the crash is in the compiler, not the produced binary.

**Do this first.** One root cause, five user-visible crashes, and float formatting is unavoidable in
real programs.

## Group 2 — integer overflow does not promote to float

PHP semantics: integer arithmetic that overflows becomes a float. AOT wraps instead.

| probe | Zend | AOT |
|---|---|---|
| `PHP_INT_MAX + 1` | `float(9.2233720368548E+18)` | `int(-9223372036854775808)` |
| `PHP_INT_MAX * 2` | `float(1.8446744073709552E+19)` | `int(2)` |

Silent wrong output on arithmetic — the characteristic failure class named in `AGENTS.md` §3. No
crash, no warning; the program simply computes a different number.

Related numeric mismatches, same area:

- `intdiv`-style const-folded division truncates: `7/2` const-folds to `int(3)` instead of `float(3.5)`
- float post-increment truncates: `$x = 1.5; $x++` gives `int(2)`, Zend gives `float(2.5)`
- `PHP_INT_MIN % -1` gives `int(-1)` / `int(-9223372036854775808)`; Zend gives `int(0)` for both
- `abs(PHP_INT_MIN + 1)` returns a float; Zend returns `int(9223372036854775807)`
- `--$null` decrements; in Zend decrementing null is a no-op
- `var_dump` of a float uses `precision` rather than `serialize_precision`
- `echo` of `1.0E+100` prints `1e+100`; Zend prints `1.0E+100`
- `var_dump(INF)` prints `float(inf)`; Zend prints `float(INF)`
- an `(int)`/`(float)` cast passed **directly as a call argument** yields `NULL`

## Group 3 — static property storage

Several wrong-output cases. One deserves separate, urgent handling:

**`static-prop-write-from-closure` returns an unstable garbage integer** — a different value between
runs (`-6341068275337658368` in one). That is a read of uninitialised memory, not a logic error, and
it should be filed and fixed on its own terms.

Others in the group:

- a static array property's default is lost (`3` → `0`)
- a static property increment inside a method loses one update (`2` → `1`)
- a child class does not share its parent's static property (`42` → `1`)
- a static array property returned by value aliases instead of copying (`1` → `99`)
- `??=` to a static property is not stored (`int(7)` → `NULL`)
- a function-static array element write is lost across calls (`12` → `11`)
- `&` reference to a static property is not bound

Plus three aborts (exit 134): array-callable to a static method, string-callable to a static method,
and a static method called on an instance.

## Group 4 — LLVM module verification failures

These fail with `Uncaught RuntimeException: Module verification failed`, i.e. the emitted IR is
invalid. Likely a small number of shared causes.

- `2 ** 10` (pow operator)
- `base_convert(...)`
- `property_exists()` on a class with a static property
- writing to a function-`static` string variable

## Group 5 — unimplemented lowering (honest errors, not corruption)

These abort with a clear message rather than producing wrong output, so they are lower priority than
groups 1–3 — but they are ordinary PHP:

- `"5" + 5` → `Reached end of switch, can't handle binary operation yet: TYPE_PLUS`
- `NAN <=> 1.0` → same, `TYPE_SPACESHIP`
- enum case as a class-constant value → `Unsupported compile-time constant for JIT (vm type 9)`
- `$obj::method()` / variable class in a static call → `Static call class must be a literal`
- interface constant via `self::` in a const expression → `Undefined constant C::X`
- array literal stored to a static property → `JIT static property boxed store does not support value type __string__`

---

## Group 6 — inherited property defaults **[verified]** (#31895)

Filed separately because it is verified and self-contained.

```php
class A { public string $p = 'hi'; }
class B extends A {}
echo (new B)->p;
```

| case | Zend | AOT |
|---|---|---|
| instantiate `A` directly | `hi` | `hi` |
| instantiate `B extends A`, typed | `hi` | `Uncaught Error: Typed property A::$p must not be accessed before initialization` |
| instantiate `B extends A`, untyped | `hi` | `Warning: Undefined property: A::$p` then empty |

Any class hierarchy with a base-class property default is broken. The untyped variant produces
silently wrong output.

---

## Suggested order

1. **Group 1** — one fix, five crashes, unavoidable in real code.
2. **Group 3's uninitialised-memory read** — unstable output is worse than wrong output, because it
   is not reproducible and will not stay caught.
3. **Group 2** — silent wrong arithmetic; wide blast radius, no diagnostic.
4. **Group 6** (#31895) — wide blast radius, already verified and minimal.
5. **Group 4** — invalid IR; probably few causes.
6. **Group 5** — honest failures; safe to schedule last.

## How to work these

- `script/aot-smoke.sh` must be 8/8 **before** and after any change. If it is not 8/8, stop: you are
  measuring the toolchain.
- Add a compliance case per fix; a fix without a case will regress unnoticed.
- Fan out with `isolation: 'worktree'` if using subagents — concurrent containers sharing the
  bind-mounted helper cache corrupt each other, and each needs its own
  `PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR`.
- Groups 1–4 are independent; they parallelise cleanly. Group 5 items mostly do not share causes.
