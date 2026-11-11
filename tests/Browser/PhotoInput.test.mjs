import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import ts from 'typescript';

const source = await readFile(new URL('../../resources/js/photos.ts', import.meta.url), 'utf8');
const compiled = ts.transpileModule(source, {
    compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 },
}).outputText;
const { encodePhoto, snapshotPhoto } = await import('data:text/javascript;base64,' + Buffer.from(compiled).toString('base64'));

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

test('copies a camera frame into a scaled JPEG for preview and upload', async t => {
    const video = { videoWidth: 2160, videoHeight: 3840 };
    const jpeg = new Blob([Uint8Array.of(255, 216, 255, 224)], { type: 'image/jpeg' });
    const drawImage = t.mock.fn();
    const canvas = {
        getContext: () => ({ fillRect() {}, drawImage }),
        toBlob(resolve, format) {
            assert.equal(format, 'image/jpeg');
            resolve(jpeg);
        },
    };
    globalThis.document = { createElement: () => canvas };
    t.after(() => { delete globalThis.document; });

    const file = await encodePhoto(video, video.videoWidth, video.videoHeight);

    assert.equal(canvas.width, 1152);
    assert.equal(canvas.height, 2048);
    assert.deepEqual(drawImage.mock.calls[0].arguments, [video, 0, 0, 1152, 2048]);
    assert.equal(file.name, 'missao.jpg');
    assert.equal(file.type, 'image/jpeg');
    assert.deepEqual(await file.arrayBuffer(), await jpeg.arrayBuffer());
});
