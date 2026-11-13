const reais = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

export const money = (cents: number) => reais.format(cents / 100);
