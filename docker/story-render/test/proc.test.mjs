import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { descendantsOf, killLeftovers, killTree, runKillable } from '../lib/proc.mjs';
import { STUB, pidIsAlive, tmpDir, waitFor } from '../testkit/helpers.mjs';

const pidsFrom = (pidFile) => readFileSync(pidFile, 'utf8').split(' ').map(Number);

const runHang = async (killOptions, timeoutMs = 1200) => {
    const dir = tmpDir();
    const pidFile = path.join(dir, 'pids');
    const result = await runKillable({ command: process.execPath, args: [STUB, 'hang', path.join(dir, 'out.mp4'), pidFile], cwd: dir, env: { PATH: process.env.PATH }, timeoutMs, killOptions });
    const [childPid, grandchildPid] = pidsFrom(pidFile);

    return { result, childPid, grandchildPid };
};

const cleanup = (...pids) => pids.forEach((pid) => { try { process.kill(pid, 'SIGKILL'); } catch {} });

test('kill tree: a hanging render and its grandchild in its own session are gone after the timeout', async () => {
    const { result, childPid, grandchildPid } = await runHang(undefined);
    try {
        assert.equal(result.timedOut, true);
        assert.ok(await waitFor(() => !pidIsAlive(childPid)), 'render process still alive');
        assert.ok(await waitFor(() => !pidIsAlive(grandchildPid)), 'grandchild in a new session still alive');
    } finally {
        cleanup(childPid, grandchildPid);
    }
});

test('kill tree: the group kill alone leaves a grandchild in its own session alive (why the descendant walk exists)', async () => {
    const { childPid, grandchildPid } = await runHang({ group: true, descendants: false });
    try {
        assert.ok(await waitFor(() => !pidIsAlive(childPid)), 'render process should be gone');
        assert.equal(pidIsAlive(grandchildPid), true, 'a setsid grandchild survives a group-only kill');
    } finally {
        cleanup(childPid, grandchildPid);
    }
});

test('kill tree: with both halves switched off nothing is killed beyond the direct signal path', async () => {
    const { childPid, grandchildPid } = await runHang({ group: false, descendants: false });
    try {
        assert.equal(pidIsAlive(grandchildPid), true);
    } finally {
        cleanup(childPid, grandchildPid);
    }
});

test('kill tree: an abort signal ends the render and its grandchild', async () => {
    const dir = tmpDir();
    const pidFile = path.join(dir, 'pids');
    const abort = new AbortController();
    const running = runKillable({ command: process.execPath, args: [STUB, 'hang', path.join(dir, 'out.mp4'), pidFile], cwd: dir, env: { PATH: process.env.PATH }, timeoutMs: 30000, signal: abort.signal });
    assert.ok(await waitFor(() => { try { return readFileSync(pidFile, 'utf8').includes(' '); } catch { return false; } }));
    const [childPid, grandchildPid] = pidsFrom(pidFile);
    abort.abort();
    const result = await running;
    try {
        assert.equal(result.aborted, true);
        assert.ok(await waitFor(() => !pidIsAlive(childPid) && !pidIsAlive(grandchildPid)));
    } finally {
        cleanup(childPid, grandchildPid);
    }
});

test('descendants: finds children across process groups and sessions', async () => {
    const parent = spawn(process.execPath, [STUB, 'hang', '/dev/null', path.join(tmpDir(), 'pids')], { detached: true, stdio: 'ignore' });
    try {
        assert.ok(await waitFor(() => descendantsOf(parent.pid).length >= 1));
    } finally {
        killTree(parent.pid);
    }
});

test('leftover sweep: kills a matching orphan and nothing else', async () => {
    const nonce = `story-test-orphan-${process.pid}-${Date.now()}`;
    const orphan = spawn(process.execPath, ['-e', 'setInterval(() => {}, 1000)', nonce], { detached: true, stdio: 'ignore' });
    const bystander = spawn(process.execPath, ['-e', 'setInterval(() => {}, 1000)', 'story-test-bystander'], { detached: true, stdio: 'ignore' });
    try {
        assert.ok(await waitFor(() => pidIsAlive(orphan.pid) && pidIsAlive(bystander.pid)));
        const killed = killLeftovers(new RegExp(nonce));
        assert.deepEqual(killed, [orphan.pid]);
        assert.ok(await waitFor(() => !pidIsAlive(orphan.pid)));
        assert.equal(pidIsAlive(bystander.pid), true);
    } finally {
        cleanup(orphan.pid, bystander.pid);
    }
});
