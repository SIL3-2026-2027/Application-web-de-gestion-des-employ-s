import axios, { AxiosError } from 'axios'

export const api = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json' },
})

/** Récupère le cookie XSRF-TOKEN avant toute requête qui modifie l'état (Sanctum). */
let csrfReady: Promise<unknown> | null = null
export function ensureCsrf() {
  csrfReady ??= axios.get('/sanctum/csrf-cookie', { withCredentials: true })
  return csrfReady
}

api.interceptors.request.use(async (config) => {
  if (config.method && config.method !== 'get') {
    await ensureCsrf()
  }
  return config
})

api.interceptors.response.use(
  (r) => r,
  (error: AxiosError) => {
    // Jeton CSRF expiré : on le redemandera à la prochaine requête
    if (error.response?.status === 419) csrfReady = null
    return Promise.reject(error)
  },
)

export type ValidationErrors = Record<string, string[]>

/** Extrait un message lisible d'une erreur API. */
export function errorMessage(error: unknown): string {
  if (error instanceof AxiosError) {
    const data = error.response?.data as { message?: string } | undefined
    if (error.response?.status === 403) return "Vous n'avez pas les droits pour cette action."
    if (data?.message) return data.message
  }
  return 'Une erreur est survenue. Réessayez.'
}

export function validationErrors(error: unknown): ValidationErrors {
  if (error instanceof AxiosError && error.response?.status === 422) {
    return (error.response.data as { errors?: ValidationErrors }).errors ?? {}
  }
  return {}
}
