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
