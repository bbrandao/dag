export async function requestPreference(path: string, method: 'PATCH' | 'POST' | 'DELETE', body: object): Promise<boolean> {
    const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='));
    const response = await fetch(path, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '',
        },
        body: JSON.stringify(body),
    });
    if (!response.ok) throw new Error('Não foi possível salvar a preferência.');

    const data = await response.json();
    if (typeof data.enabled !== 'boolean') throw new Error('Resposta de preferência inválida.');

    return data.enabled;
}
