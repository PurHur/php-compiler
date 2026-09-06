# aarch64-linux helper-runtime (#36391)

This directory is the committed helper-cache tier for `PHP_COMPILER_TARGET=aarch64-linux`
(see `lib/AOT/CompileTarget.php`).

LLVM object emit is TargetMachine-driven (triple `aarch64-unknown-linux-gnu`, CPU `generic`).
On an x86_64 host the AArch64 backend is initialized explicitly — it does **not** fall back
to host MCJIT (that would write x86_64 ELF into this tree). `Linker` still refuses a
non-native link.

`script/check-helper-runtime-prelink.php --all-arches` asserts every committed `unit.o`
(and `common.o` when present) has ELF `e_machine=183` (EM_AARCH64).

## Seed corpus (VM_* + lib_VM_* + ext/standard tiers)

**122** committed `unit.o` files:

- full `VM_*` (13) and `lib_VM_*` (9) sets from `x86_64-linux`
- first `ext/standard` tier (10): ArrayChunk / ArrayIsList / ArraySlice / Bin2hex /
  CountChars / Crc32 / PrintR / StrWordCount / SubstrCount / VarExport
- array functional + string encode (10): ArrayMap / ArrayWalk / ArrayReduce / ArrayFind /
  ArrayMergeRecursive / ArrayCountRecursive / ArrayElem / Base64 / Hex2bin / FindSubstr
- string transform / HTML / escapes (10): StrReplace / StrPad / StrRepeat / Strrev /
  StripTags / Stripslashes / Addslashes / Htmlspecialchars / Nl2br / Ucwords
- URL / query / JSON / sprintf (10): Urlencode / Urldecode / ParseUrl / HttpBuildQuery /
  ParseStr / JsonDecode / JsonEncodeNested / JsonValidate / Sprintf / HttpResponse
- HTML decode / file I/O / class introspect (10): HtmlEntities / HtmlEntityDecode /
  HtmlspecialcharsDecode / ChunkSplit / HashEquals / FileGetContents / FilePutContents /
  ClassExists / FunctionExists / GetObjectVars
- OO hierarchy / method-property introspect (10): InterfaceExists / TraitExists /
  EnumExists / MethodExists / PropertyExists / ClassImplements / ClassParents /
  ClassUses / GetClassMethods / GetParentClass
- class vars / type / array-assoc / sort (10): GetClassVars / ClassUsesRecursive /
  UnitEnumExists / Settype / ArrayDiffAssoc / ArrayIntersectAssoc / ArrayReplaceKey /
  Sort / Usort / Round
- math / string / path / cwd / fs (10): PowInt / Clamp / Wordwrap / Quotemeta /
  Pathinfo / Getcwd / Chdir / FsDir / FsGlob / Tempnam
- time / datetime / fstat / vsprintf / substr_compare (10): Microtime / Strtotime /
  Strftime / TimezoneOffset / FormatDatetime / DateTimeFormat / Gettimeofday /
  Fstat / Vsprintf / SubstrCompare
- string compare / CSV / escapes / metaphone (10): CaseCompare / NCompare /
  CharInMask / Levenshtein / Cslashes / CsvFputcsv / CsvStrGetcsv / ConvertUu /
  Hebrev / Metaphone

Refresh / expand via:

```bash
./script/seed-aarch64-helper-runtime.sh           # emit + publish (~2 min)
./script/seed-aarch64-helper-runtime.sh --check   # count + ELF gate
```

Seed units may be published from any host with LLVM 9 AArch64 (object emit only). Full corpus
refresh still needs a native aarch64 machine (or a longer QEMU job outside the 20 min cap).

On x86_64 CI, prove user-program object emit for the aarch64 triple (no link) via:

```bash
./script/aot-smoke-cross-emit.sh                  # 8/8 EM_AARCH64 objects
# native aarch64: also runs full ./script/aot-smoke.sh under PHP_COMPILER_TARGET
```

```bash
# one unit → committed tier (unique cache dir; 20 min / 8g cap)
PHP_COMPILER_TARGET=aarch64-linux \
PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR=build/helper-runtime-cache-aarch64 \
  php script/emit-helper-runtime-object.php --unit=/VM/CoalesceJitHelper.php
PHP_COMPILER_TARGET=aarch64-linux \
PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR=build/helper-runtime-cache-aarch64 \
  php script/publish-helper-units-prelink.php /VM/CoalesceJitHelper.php

# curated seed (preferred):
./script/seed-aarch64-helper-runtime.sh

# native aarch64 only — full corpus:
PHP_COMPILER_TARGET=aarch64-linux make helper-runtime-prelink-refresh
```

Release images stage **both** Linux helper arches; `Docker/release/Dockerfile`
keeps only `TARGETARCH`'s tree so multi-arch `ghcr.io/purhur/phpc` digests stay
honest (no wrong-arch 798 MiB corpus). Darwin aarch64 remains data-only until
LLVM 22 — see `docs/adr/36391-aarch64-darwin-deferred.md`.
