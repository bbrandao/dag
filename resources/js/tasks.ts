export const frequencies = {
    daily: 'Diária',
    weekly: 'Semanal',
    monthly: 'Mensal',
    yearly: 'Anual',
};

export type Frequency = keyof typeof frequencies;

export const missionStatuses = {
    pending: 'A fazer',
    in_review: 'Em revisão',
    adjustment: 'Precisa de ajuste',
    approved: 'Aprovada',
    rejected: 'Reprovada',
    expired: 'Vencida',
};

export type MissionStatus = keyof typeof missionStatuses;

export const weekDays = ['Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado', 'Domingo'];

export const months = [
    'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
    'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro',
];

export function formatDate(date: string) {
    return date.split('-').reverse().join('/');
}

export function nameInitials(name: string) {
    const parts = name.trim().split(/\s+/);
    const first = Array.from(parts[0])[0] ?? '';
    const last = parts.length > 1 ? Array.from(parts[parts.length - 1])[0] ?? '' : '';
    return `${first}${last}`.toLocaleUpperCase('pt-BR');
}
