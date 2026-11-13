import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, errorMessage } from '../api/client'
import type { NotificationPreference } from '../api/types'
import { useAuth } from '../auth/useAuth'
import { Alert, Card, PageHeader, Spinner } from '../components/ui'

/** NOT-05 : chaque utilisateur choisit les notifications qu'il reçoit aussi par e-mail. */
export default function NotificationPreferencesPage() {
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const { data, isLoading } = useQuery({
    queryKey: ['notification-preferences'],
    queryFn: async () => (await api.get<NotificationPreference[]>('/notification-preferences')).data,
  })

  const save = useMutation({
    mutationFn: async (pref: { key: string; email: boolean }) =>
      (await api.put<NotificationPreference[]>('/notification-preferences', { email: { [pref.key]: pref.email } })).data,
    onSuccess: (prefs) => queryClient.setQueryData(['notification-preferences'], prefs),
  })

  return (
    <>
      <PageHeader title="Préférences de notification" subtitle={`Les e-mails sont envoyés à ${user?.email}`} />
      <Card>
        {isLoading ? (
          <Spinner />
        ) : (
          <>
            <p className="mb-4 text-sm text-slate-600">Toutes les notifications apparaissent dans la cloche de l'application. Choisissez celles que vous recevez aussi par e-mail.</p>
            <ul className="divide-y divide-slate-100">
              {data?.map((p) => (
                <li key={p.key} className="flex items-center justify-between gap-4 py-3">
                  <span className="text-sm text-slate-800">{p.label}</span>
                  <label className="relative inline-flex cursor-pointer items-center">
                    <input
                      type="checkbox"
                      className="peer sr-only"
                      checked={p.email}
                      disabled={save.isPending}
                      onChange={(e) => save.mutate({ key: p.key, email: e.target.checked })}
                    />
                    <span className="h-6 w-11 rounded-full bg-slate-300 transition peer-checked:bg-brand-500 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-100" />
                    <span className="absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5" />
                    <span className="ml-3 w-14 text-xs text-slate-500">{p.email ? 'E-mail' : 'Désactivé'}</span>
                  </label>
                </li>
              ))}
            </ul>
            {save.isError && (
              <div className="mt-4">
                <Alert>{errorMessage(save.error)}</Alert>
              </div>
            )}
          </>
        )}
      </Card>
    </>
  )
}
