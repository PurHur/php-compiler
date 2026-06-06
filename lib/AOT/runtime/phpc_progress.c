/**
 * JIT/AOT progress notes + segfault triage (#2978, #2967). Thin platform ABI only.
 * Argv storage lives in lib/JIT/Builtin/CliArgvRuntime.php (#6341).
 */
#include <stdio.h>
#include <stddef.h>
#include <stdlib.h>
#include <string.h>
#include <signal.h>
#include <unistd.h>

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

void __phpc_progress_note(const char *msg)
{
    const char *progress_path;
    const char *phase_path;
    const char *entry_path;
    FILE *fp;
    size_t len;

    phpc_install_segv_handler();
    if (NULL == msg) {
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

    progress_path = getenv("PHP_COMPILER_JIT_PROGRESS_FILE");
    phase_path = getenv("PHP_COMPILER_JIT_PHASE_FILE");
    entry_path = getenv("PHP_COMPILER_JIT_ENTRY_FILE");

    if ((NULL == progress_path || '\0' == *progress_path) && (NULL == phase_path || '\0' == *phase_path) && (NULL == entry_path || '\0' == *entry_path)) {
        return;
    }

    if (NULL != progress_path && '\0' != *progress_path) {
        fp = fopen(progress_path, "wb");
        if (NULL != fp) {
            if (len > 0) {
                (void) fwrite(msg, 1, len, fp);
            }
            (void) fclose(fp);
        }
    }

    if (NULL != phase_path && '\0' != *phase_path) {
        fp = fopen(phase_path, "wb");
        if (NULL != fp) {
            if (len > 0) {
                (void) fwrite(msg, 1, len, fp);
            }
            (void) fclose(fp);
        }
    }

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
