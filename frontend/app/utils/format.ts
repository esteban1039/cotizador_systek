// Format server-provided decimals without converting monetary amounts to float.
export function money(value?: string): string {
  if (value === undefined) return '—'
  const [whole = '0', fraction = '00'] = value.split('.')
  const formatted = new Intl.NumberFormat('es-CO').format(BigInt(whole))
  return `$ ${formatted}${fraction === '00' ? '' : `,${fraction.padEnd(2, '0')}`}`
}
export function cents(value: string | number): string {
  const amount = BigInt(value)
  return money(`${amount / 100n}.${String(amount % 100n).padStart(2, '0')}`)
}
export function dateLabel(value: string): string {
  return new Intl.DateTimeFormat('es-CO', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'America/Bogota' }).format(new Date(`${value.slice(0, 10)}T12:00:00-05:00`))
}
export function errorMessages(error: unknown): string[] {
  const failure = error as { data?: { message?: string; data?: { errors?: Record<string, string[]> } } }
  const fields = failure.data?.data?.errors
  return fields ? Object.values(fields).flat() : [failure.data?.message || 'No pudimos completar la solicitud. Intenta de nuevo.']
}
