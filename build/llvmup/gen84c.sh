#!/bin/bash
export DEBIAN_FRONTEND=noninteractive
R=/app/build/llvmup/gen84c-results.txt
: > $R
exec 2>&1
apt-get update -qq >/dev/null 2>&1
apt-get install -y -qq curl gnupg ca-certificates git unzip python3 libffi-dev >/dev/null 2>&1
docker-php-ext-install ffi >/dev/null 2>&1
php -m | grep -qix ffi && echo "FFI extension: ENABLED" >> $R || echo "FFI extension: MISSING" >> $R
curl -fsSL https://apt.llvm.org/llvm-snapshot.gpg.key | gpg --dearmor -o /etc/apt/trusted.gpg.d/llvm.gpg 2>/dev/null
CODENAME=$(. /etc/os-release; echo "${VERSION_CODENAME}")
echo "deb http://apt.llvm.org/${CODENAME}/ llvm-toolchain-${CODENAME}-22 main" > /etc/apt/sources.list.d/llvm22.list
apt-get update -qq >/dev/null 2>&1
apt-get install -y -qq llvm-22-dev libllvm22 >/dev/null 2>&1
INC=/usr/lib/llvm-22/include
cat > /tmp/all.h <<'HDR'
#include <llvm-c/Core.h>
#include <llvm-c/Target.h>
#include <llvm-c/TargetMachine.h>
#include <llvm-c/ExecutionEngine.h>
#include <llvm-c/Analysis.h>
#include <llvm-c/BitReader.h>
#include <llvm-c/BitWriter.h>
#include <llvm-c/IRReader.h>
#include <llvm-c/Linker.h>
#include <llvm-c/Support.h>
#include <llvm-c/Transforms/PassBuilder.h>
HDR
cpp -P -I"$INC" /tmp/all.h > /tmp/flat_raw.h 2>>$R
python3 - <<'PY'
def strip_balanced(s, kw):
    out, i, n = [], 0, len(s)
    while True:
        k = s.find(kw, i)
        if k < 0:
            out.append(s[i:]); break
        out.append(s[i:k])
        j = k + len(kw)
        while j < n and s[j].isspace(): j += 1
        if j < n and s[j] == '(':
            depth = 0
            while j < n:
                if s[j] == '(': depth += 1
                elif s[j] == ')':
                    depth -= 1
                    if depth == 0: j += 1; break
                j += 1
        i = j
    return ''.join(out)

import re
src = open('/tmp/flat_raw.h').read()
src = strip_balanced(src, '__attribute__')     # balanced: handles ((visibility("default")))
src = src.replace('__extension__', '').replace('__inline__', '').replace('__restrict', '')
# drop static/inline function BODIES; FFIMe wants declarations only
out, i, n = [], 0, len(src)
while True:
    m = re.search(r'\b(?:static|inline)\b[^;{]*\{', src[i:])
    if not m: out.append(src[i:]); break
    out.append(src[i:i+m.start()])
    depth, j = 0, i + m.end() - 1
    while j < n:
        if src[j] == '{': depth += 1
        elif src[j] == '}':
            depth -= 1
            if depth == 0: j += 1; break
        j += 1
    i = j
src = ''.join(out)
open('/tmp/flat.h','w').write(src)
PY
echo "flat lines: $(wc -l < /tmp/flat.h)" >> $R
echo "attrs left: $(grep -c '__attribute__' /tmp/flat.h)" >> $R
echo "lines starting with stray ): $(grep -c '^)' /tmp/flat.h)" >> $R
echo "--- first 4 decls ---" >> $R
grep -m4 'LLVM' /tmp/flat.h >> $R
cp /tmp/flat.h /app/build/llvmup/llvm22-flat.h
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer >/dev/null 2>&1
cd /tmp && rm -rf fg && mkdir fg && cd fg
echo '{ "require": { "ircmaxell/ffime": "dev-master" }, "minimum-stability": "dev", "prefer-stable": true }' > composer.json
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction >/dev/null 2>&1
mkdir -p hdr && cp /tmp/flat.h hdr/llvm22flat.h
cat > gen.php <<'PHPX'
<?php
require __DIR__.'/vendor/autoload.php';
$so = glob('/usr/lib/llvm-22/lib/libLLVM*.so*')[0] ?? '';
$llvm = new FFIMe\FFIMe($so, [__DIR__.'/hdr/']);
$llvm->include('llvm22flat.h');
$llvm->codegen('llvm22\\llvm', '/app/build/llvmup/llvm22.php');
echo "GENERATED OK\n";
PHPX
echo "== ffime run ==" >> $R
php -d memory_limit=-1 gen.php 2>&1 | tail -10 >> $R
wc -l /app/build/llvmup/llvm22.php >> $R 2>&1 || echo "no output file" >> $R
echo "GEN84C_DONE" >> $R
