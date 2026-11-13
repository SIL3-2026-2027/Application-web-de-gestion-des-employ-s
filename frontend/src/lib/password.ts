/** AUTH-03 : mêmes règles que l'API (10 caractères, majuscule, minuscule, chiffre, caractère spécial). */
export const PASSWORD_RULES: { label: string; test: (p: string) => boolean }[] = [
  { label: '10 caractères minimum', test: (p) => p.length >= 10 },
  { label: 'Une majuscule et une minuscule', test: (p) => /[A-Z]/.test(p) && /[a-z]/.test(p) },
  { label: 'Un chiffre', test: (p) => /\d/.test(p) },
  { label: 'Un caractère spécial (!?@#…)', test: (p) => /[^A-Za-z0-9]/.test(p) },
]

export function isStrongPassword(p: string): boolean {
  return PASSWORD_RULES.every((r) => r.test(p))
}

/** Génère un mot de passe conforme, à communiquer à l'employé. */
export function generatePassword(): string {
  const sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!?@#$%&*']
  const all = sets.join('')
  const pick = (chars: string) => chars[crypto.getRandomValues(new Uint32Array(1))[0] % chars.length]
  const chars = [...sets.map(pick), ...Array.from({ length: 8 }, () => pick(all))]
  for (let i = chars.length - 1; i > 0; i--) {
    const j = crypto.getRandomValues(new Uint32Array(1))[0] % (i + 1)
    ;[chars[i], chars[j]] = [chars[j], chars[i]]
  }
  return chars.join('')
}
