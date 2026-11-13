import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, errorMessage, validationErrors } from '../../api/client'
import type { User } from '../../api/types'
import { useAuth } from '../../auth/useAuth'
import PasswordFields from '../../components/PasswordFields'
import { isStrongPassword } from '../../lib/password'
import { Alert, Button, Field, Input } from '../../components/ui'

/**
 * AUTH-06 : changement de son mot de passe.
 * Obligatoire à la première connexion quand l'administration a saisi le mot de passe.
 */
export default function ChangePasswordPage() {
  const { user, logout } = useAuth()
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const forced = user?.must_change_password ?? false

  const [current, setCurrent] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [done, setDone] = useState(false)

  const save = useMutation({
    mutationFn: async () =>
      (await api.put<{ data: User }>('/me/password', { current_password: current, password, password_confirmation: confirmation })).data.data,
    onSuccess: (u) => {
      queryClient.setQueryData(['me'], u)
      if (forced) {
        queryClient.invalidateQueries()
        navigate('/', { replace: true })
      } else {
        setDone(true)
        setCurrent('')
        setPassword('')
        setConfirmation('')
      }
    },
  })
  const errors = validationErrors(save.error)
  const ready = current !== '' && isStrongPassword(password) && password === confirmation

  const form = (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault()
        save.mutate()
      }}
    >
      {forced && (
        <Alert tone="success">
          Bienvenue {user?.name.split(' ')[0]} ! Votre compte a été créé par l'administration. Choisissez votre propre mot de passe pour continuer.
        </Alert>
      )}
      {done && <Alert tone="success">Votre mot de passe a été modifié.</Alert>}
      <Field label={forced ? 'Mot de passe reçu' : 'Mot de passe actuel'} required error={errors.current_password?.[0]}>
        <Input type="password" autoComplete="current-password" value={current} onChange={(e) => setCurrent(e.target.value)} />
      </Field>
      <PasswordFields
        label="Nouveau mot de passe"
        password={password}
        confirmation={confirmation}
        onChange={(p, c) => {
          setPassword(p)
          setConfirmation(c)
        }}
        error={errors.password?.[0]}
      />
      {save.isError && Object.keys(errors).length === 0 && <Alert>{errorMessage(save.error)}</Alert>}
      <div className="flex justify-between gap-2">
        {forced ? (
          <Button type="button" variant="ghost" onClick={() => logout.mutate(undefined, { onSettled: () => navigate('/connexion') })}>
            Se déconnecter
          </Button>
        ) : (
          <span />
        )}
        <Button type="submit" loading={save.isPending} disabled={!ready}>
          Enregistrer
        </Button>
      </div>
    </form>
  )

  if (forced) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-gradient-to-br from-brand-700 to-brand-500 p-4">
        <div className="w-full max-w-md rounded-xl bg-white p-8 shadow-xl">
          <h1 className="mb-5 text-xl font-semibold text-slate-900">Choisissez votre mot de passe</h1>
          {form}
        </div>
      </div>
    )
  }

  return (
    <div className="max-w-md">
      <h1 className="mb-6 text-2xl font-semibold text-slate-900">Changer mon mot de passe</h1>
      <div className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">{form}</div>
    </div>
  )
}
