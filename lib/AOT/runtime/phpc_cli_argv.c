/**
 * Store process argc/argv for native M3 emit / compiled compile driver (#1937, #2697, #2794).
 */
#include <stdio.h>
#include <stddef.h>
#include <stdlib.h>
#include <string.h>
#include <signal.h>
#include <unistd.h>

typedef struct __string__ __string__;
typedef struct __hashtable__ __hashtable__;
typedef struct __value__ __value__;

extern __hashtable__ *__hashtable__alloc(void);
extern __string__ *__string__init(long long size, const char *value);
extern void __hashtable__setStringAt(__hashtable__ *ht, size_t index, __string__ *val);
extern void __value__writeHashtable(__value__ *out, __hashtable__ *ht);

static int phpc_cli_argc = 0;
static char **phpc_cli_argv = NULL;

static int phpc_segv_handler_installed = 0;
static char phpc_last_progress[256];
static size_t phpc_last_progress_len = 0;

static void phpc_segv_handler(int sig)
{
    (void) sig;
    if (phpc_last_progress_len > 0) {
        (void) write(2, "phpc: fatal signal (segfault) after ", 37);
        (void) write(2, phpc_last_progress, phpc_last_progress_len);
        (void) write(2, "\n", 1);
    } else {
        (void) write(2, "phpc: fatal signal (segfault)\n", 30);
    }
    _exit(139);
}

static void phpc_install_segv_handler(void)
{
    if (phpc_segv_handler_installed) {
        return;
    }
    phpc_segv_handler_installed = 1;
    (void) signal(SIGSEGV, phpc_segv_handler);
}

static void phpc_progress_note(const char *msg)
{
    const char *path;
    FILE *fp;
    size_t len;

    phpc_install_segv_handler();
    path = getenv("PHP_COMPILER_JIT_PROGRESS_FILE");
    if (NULL == path || '\0' == *path || NULL == msg) {
        return;
    }
    len = strlen(msg);
    if (len > 0) {
        if (len >= sizeof(phpc_last_progress)) {
            len = sizeof(phpc_last_progress) - 1;
        }
        memcpy(phpc_last_progress, msg, len);
        phpc_last_progress[len] = '\0';
        phpc_last_progress_len = len;
    }
    fp = fopen(path, "wb");
    if (NULL == fp) {
        return;
    }
    if (len > 0) {
        (void) fwrite(msg, 1, len, fp);
    }
    (void) fclose(fp);
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
