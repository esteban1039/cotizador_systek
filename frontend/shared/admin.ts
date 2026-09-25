export const families = { cctv: 'CCTV', data_power: 'Datos y potencia', equipment: 'Equipos', software: 'Software y licencias', ups: 'UPS', security: 'Seguridad y renovaciones', services: 'Servicios técnicos' }
export const roles = { admin: 'Administrador', quoter: 'Cotizador', approver: 'Aprobador' }
export const clauseTypes = { scope_base: 'Alcance', exclusions: 'Exclusiones', payment: 'Forma de pago', warranty: 'Garantía', validity: 'Vigencia', observations: 'Observaciones' } as const
export function bps(value: string): number { const [whole = '', decimal = ''] = value.replace(',', '.').split('.'); return /^\d+(?:[.,]\d{1,2})?$/.test(value) ? Number(whole) * 100 + Number(decimal.padEnd(2, '0')) : NaN }
