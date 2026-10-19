import { execFileSync, spawn } from 'node:child_process';
import { readFileSync, readdirSync } from 'node:fs';

/**
 * Process table as `{pid, ppid, command}` rows. Linux reads /proc (always
 * present in the container); other systems (developer laptops running the
 * tests) fall back to `ps`.
 */
export const processTable = () => {
    if (process.platform === 'linux') {
        const rows = [];
        for (const entry of readdirSync('/proc')) {
            if (!/^\d+$/.test(entry)) {
                continue;
            }
            try {
                const stat = readFileSync(`/proc/${entry}/stat`, 'utf8');
                // The command name sits in parentheses and may contain spaces; ppid is the second field after it.
                const afterName = stat.slice(stat.lastIndexOf(')') + 2).split(' ');
                const command = readFileSync(`/proc/${entry}/cmdline`, 'utf8').replaceAll('\0', ' ').trim();
                rows.push({ pid: Number(entry), ppid: Number(afterName[1]), zombie: afterName[0] === 'Z', command: command || stat.slice(stat.indexOf('(') + 1, stat.lastIndexOf(')')) });
            } catch {
                // The process exited while we were reading it.
            }
        }
        return rows;
    }

    const out = execFileSync('ps', ['-axo', 'pid=,ppid=,stat=,command='], { encoding: 'utf8' });
    return out
        .split('\n')
        .map((line) => /^\s*(\d+)\s+(\d+)\s+(\S+)\s+(.*)$/.exec(line))
        .filter(Boolean)
        .map((m) => ({ pid: Number(m[1]), ppid: Number(m[2]), zombie: m[3].startsWith('Z'), command: m[4] }));
};

/**
 * Every descendant of `rootPid`, whatever session or process group it lives in.
 * Chromium and ffmpeg may leave the render's process group, so a group kill alone
 * is not enough.
 */
export const descendantsOf = (rootPid, table = processTable()) => {
    const childrenByParent = new Map();
    for (const row of table) {
        const list = childrenByParent.get(row.ppid) ?? [];
        list.push(row.pid);
        childrenByParent.set(row.ppid, list);
    }
    const found = [];
    const queue = [rootPid];
    while (queue.length > 0) {
        for (const child of childrenByParent.get(queue.shift()) ?? []) {
            found.push(child);
            queue.push(child);
        }
    }
    return found;
};

const sendKill = (pid) => {
    try {
        process.kill(pid, 'SIGKILL');
    } catch {
        // Already gone.
    }
};

/**
 * Ends a detached child and everything below it: first the descendant walk
 * (finds processes that started their own session), then the process group.
 * `options` exists so the tests can switch each half off and prove each is needed.
 */
export const killTree = (pid, { group = true, descendants = true } = {}) => {
    for (let pass = 0; pass < 5; pass += 1) {
        const victims = descendants ? descendantsOf(pid) : [];
        for (const victim of victims) {
            sendKill(victim);
        }
        if (group) {
            try {
                process.kill(-pid, 'SIGKILL');
            } catch {
                // Group already gone.
            }
        }
        sendKill(pid);
        if (!descendants || descendantsOf(pid).length === 0) {
            return;
        }
    }
};

/**
 * Kills every live process whose command line matches `pattern`, except this
 * process (already dead, not yet reaped zombies are skipped). With one render at a time and nothing else spawning chromium or
 * ffmpeg in the container, whatever matches after a job is a leftover.
 *
 * @returns {number[]} the killed pids
 */
export const killLeftovers = (pattern) => {
    const killed = [];
    for (const row of processTable()) {
        if (row.pid !== process.pid && !row.zombie && pattern.test(row.command)) {
            sendKill(row.pid);
            killed.push(row.pid);
        }
    }
    return killed;
};

const tailAppend = (current, chunk, max = 4000) => (current + chunk).slice(-max);

/**
 * Runs a command in its own process group with a hard timeout and an abort
 * signal. On timeout, abort or error the whole process tree is killed.
 *
 * @returns {Promise<{exitCode: number|null, timedOut: boolean, aborted: boolean, stderrTail: string}>}
 */
export const runKillable = ({ command, args, cwd, env, timeoutMs, signal, killOptions }) =>
    new Promise((resolve) => {
        const child = spawn(command, args, { cwd, env, detached: true, stdio: ['ignore', 'pipe', 'pipe'] });
        let stderrTail = '';
        let timedOut = false;
        let aborted = false;
        let settled = false;

        child.stdout.on('data', () => {});
        child.stderr.on('data', (chunk) => {
            stderrTail = tailAppend(stderrTail, chunk.toString('utf8'));
        });

        const finish = (exitCode) => {
            if (settled) {
                return;
            }
            settled = true;
            clearTimeout(timer);
            signal?.removeEventListener('abort', onAbort);
            // The direct child may be gone while descendants still run.
            killTree(child.pid, killOptions);
            resolve({ exitCode, timedOut, aborted, stderrTail });
        };

        const onAbort = () => {
            aborted = true;
            killTree(child.pid, killOptions);
            finish(null);
        };

        const timer = setTimeout(() => {
            timedOut = true;
            killTree(child.pid, killOptions);
            finish(null);
        }, timeoutMs);

        if (signal?.aborted) {
            onAbort();
            return;
        }
        signal?.addEventListener('abort', onAbort, { once: true });

        child.on('error', () => finish(null));
        child.on('close', (code) => finish(code));
    });
