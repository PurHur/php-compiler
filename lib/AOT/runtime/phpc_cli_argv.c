/**
 * Store process argc/argv for native M3 emit / compiled compile driver (#1937, #2697, #2794).
 */
#include <stdio.h>
#include <stddef.h>
#include <stdlib.h>
#include <string.h>

typedef struct __string__ __string__;
typedef struct __hashtable__ __hashtable__;
typedef struct __value__ __value__;

extern __hashtable__ *__hashtable__alloc(void);
extern __string__ *__string__init(long long size, const char *value);
extern void __hashtable__setStringAt(__hashtable__ *ht, size_t index, __string__ *val);
extern void __value__writeHashtable(__value__ *out, __hashtable__ *ht);

static int phpc_cli_argc = 0;
static char **phpc_cli_argv = NULL;

static void phpc_progress_note(const char *msg)
{
    const char *progress_path;
    const char *phase_path;
    const char *entry_path;
    FILE *fp;
    size_t len;

    if (NULL == msg) {
        return;
    }

    progress_path = getenv("PHP_COMPILER_JIT_PROGRESS_FILE");
    phase_path = getenv("PHP_COMPILER_JIT_PHASE_FILE");
    entry_path = getenv("PHP_COMPILER_JIT_ENTRY_FILE");

    if ((NULL == progress_path || '\0' == *progress_path) && (NULL == phase_path || '\0' == *phase_path) && (NULL == entry_path || '\0' == *entry_path)) {
        return;
    }

    len = strlen(msg);

    if (NULL != progress_path && '\0' != *progress_path) {
        fp = fopen(progress_path, "wb");
        if (NULL != fp) {
            if (len > 0) {
                (void) fwrite(msg, 1, len, fp);
            }
            (void) fclose(fp);
        }
    }

    /* Mirror progress to PHASE file for pre-PHP segfault triage (#2978/#2967). */
    if (NULL != phase_path && '\0' != *phase_path) {
        fp = fopen(phase_path, "wb");
        if (NULL != fp) {
            if (len > 0) {
                (void) fwrite(msg, 1, len, fp);
            }
            (void) fclose(fp);
        }
    }

    /* Mirror progress to ENTRY file for pre-PHP segfault triage when caller can't set it yet. */
    if (NULL != entry_path && '\0' != *entry_path) {
        fp = fopen(entry_path, "wb");
        if (NULL != fp) {
            if (len > 0) {
                (void) fwrite(msg, 1, len, fp);
            }
            (void) fclose(fp);
        }
    }
}

void __phpc_progress_note(const char *msg)
{
    phpc_progress_note(msg);
}

static __string__ *phpc_cli_cstr_to_string(const char *cstr)
{
    size_t len = 0;
    const char *p = cstr;

    if (NULL == cstr) {
        cstr = "";
        p = cstr;
    }
    while ('\0' != *p) {
        ++len;
        ++p;
    }

    return __string__init((long long) len, cstr);
}

void __phpc_cli_store_argv(int argc, char **argv)
{
    phpc_progress_note("c:cli_store_argv");
    phpc_cli_argc = argc;
    phpc_cli_argv = argv;
}

long long __phpc_cli_argc(void)
{
    return (long long) phpc_cli_argc;
}

char *__phpc_cli_argv_cstr(int index)
{
    if (NULL == phpc_cli_argv || index < 0 || index >= phpc_cli_argc) {
        return NULL;
    }

    return phpc_cli_argv[index];
}

int __phpc_cli_str_eq(const char *a, const char *b)
{
    if (NULL == a || NULL == b) {
        return 0;
    }

    return 0 == strcmp(a, b);
}

/** Populate a boxed {@link __value__} with a packed argv list for compiled CLI (#2794). */
void __phpc_cli_refresh_argv_global(__value__ *out)
{
    __hashtable__ *ht;
    int i;

    phpc_progress_note("c:cli_refresh_argv_begin");
    if (NULL == out) {
        return;
    }

    ht = __hashtable__alloc();
    for (i = 0; i < phpc_cli_argc; ++i) {
        __hashtable__setStringAt(ht, (size_t) i, phpc_cli_cstr_to_string(phpc_cli_argv[i]));
    }
    __value__writeHashtable(out, ht);
    phpc_progress_note("c:cli_refresh_argv_done");
}
