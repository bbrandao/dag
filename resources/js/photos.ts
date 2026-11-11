export async function snapshotPhoto(input: HTMLInputElement): Promise<File | null> {
    const selected = input.files?.[0];
    if (!selected) return null;

    try {
        // Read camera-backed files before resetting the native picker.
        const bytes = await selected.arrayBuffer();
        if (bytes.byteLength === 0 || bytes.byteLength !== selected.size) {
            throw new Error('Não foi possível ler a foto completa.');
        }

        return new File([bytes], selected.name, { type: selected.type, lastModified: selected.lastModified });
    } finally {
        input.value = '';
    }
}

export async function encodePhoto(source: CanvasImageSource, width: number, height: number): Promise<File | null> {
    const scale = Math.min(1, 2048 / Math.max(width, height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(width * scale));
    canvas.height = Math.max(1, Math.round(height * scale));
    const context = canvas.getContext('2d', { alpha: false });
    if (!context) return null;
    context.fillStyle = '#fff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(source, 0, 0, canvas.width, canvas.height);
    const jpeg = await new Promise<Blob | null>(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.86));
    return jpeg?.type === 'image/jpeg' ? new File([jpeg], 'missao.jpg', { type: jpeg.type }) : null;
}
