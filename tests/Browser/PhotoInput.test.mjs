import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import ts from 'typescript';

const source = await readFile(new URL('../../resources/js/photos.ts', import.meta.url), 'utf8');
const compiled = ts.transpileModule(source, {
    compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 },
}).outputText;
const { snapshotPhoto } = await import('data:text/javascript;base64,' + Buffer.from(compiled).toString('base64'));

test('copies the camera file before resetting its input', async () => {
    const bytes = Uint8Array.of(255, 216, 255, 224);
    let reset = false;
    let finishRead;
    const selected = {
        name: 'camera.jpg', type: 'image/jpeg', size: bytes.byteLength, lastModified: 123,
        arrayBuffer: () => new Promise(resolve => {
            finishRead = () => {
                assert.equal(reset, false);
                resolve(bytes.buffer);
            };
        }),
    };
    const input = { files: [selected], set value(value) { assert.equal(value, ''); reset = true; } };
    const pending = snapshotPhoto(input);
    assert.equal(reset, false);
    finishRead();
    const copied = await pending;
    assert.equal(reset, true);
    bytes.fill(0);
    assert.deepEqual(new Uint8Array(await copied.arrayBuffer()), Uint8Array.of(255, 216, 255, 224));
    assert.equal(copied.type, 'image/jpeg');
    assert.equal(copied.name, 'camera.jpg');
});

test('rejects empty camera files and read failures', async () => {
    for (const arrayBuffer of [
        async () => new ArrayBuffer(0),
        async () => new ArrayBuffer(3),
        async () => { throw new DOMException('Camera file unavailable', 'NotReadableError'); },
    ]) {
        let reset = false;
        await assert.rejects(snapshotPhoto({
            files: [{ arrayBuffer, size: 4 }],
            set value(value) { assert.equal(value, ''); reset = true; },
        }));
        assert.equal(reset, true);
    }
});

test('leaves the selection alone when the picker is cancelled', async () => {
    assert.equal(await snapshotPhoto({ files: [], set value(_) { assert.fail('Unexpected input reset'); } }), null);
});
