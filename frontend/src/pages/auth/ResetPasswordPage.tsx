import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { api, errorMessage, validationErrors } from '../../api/client'
import PasswordFields from '../../components/PasswordFields'
import { isStrongPassword } from '../../lib/password'
import { Alert, Button } from '../../components/ui'

/** Page ouverte depuis le lien reçu par e-mail après « mot de passe oublié ». */
export default function ResetPasswordPage() {
  const [params] = useSearchParams()
  const token = params.get('token') ?? ''
  const email = params.get('email') ?? ''
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')

  const reset = useMutation({
    mutationFn: async () =>
      (await api.post<{ message: string }>('/reset-password', { token, email, password, password_confirmation: confirmation })).data,
  })
  const errors = validationErrors(reset.error)

  return (
    <div className="flex min-h-screen items-center justify-center bg-gradient-to-br from-brand-700 to-brand-500 p-4">
      <div className="w-full max-w-md rounded-xl bg-white p-8 shadow-xl">
        <h1 className="text-xl font-semibold text-slate-900">Nouveau mot de passe</h1>
        <p className="mt-1 text-sm text-slate-500">{email}</p>

        {!token || !email ? (
          <div className="mt-5">
            <Alert>Ce lien est incomplet. Ouvrez-le directement depuis l'e-mail reçu.</Alert>
          </div>
        ) : reset.isSuccess ? (
          <div className="mt-5 space-y-4">
            <Alert tone="success">{reset.data.message}</Alert>
            <Link to="/connexion">
              <Button className="w-full">Se connecter</Button>
            </Link>
          </div>
        ) : (
          <form
            className="mt-5 space-y-4"
            onSubmit={(e) => {
              e.preventDefault()
              reset.mutate()
            }}
          >
            {errors.token && (
              <Alert>
                {errors.token[0]}{' '}
                <Link to="/mot-de-passe-oublie" className="font-medium underline">
                  Nouvelle demande
                </Link>
              </Alert>
            )}
            <PasswordFields
              password={password}
              confirmation={confirmation}
              onChange={(p, c) => {
                setPassword(p)
                setConfirmation(c)
              }}
              error={errors.password?.[0]}
            />
            {reset.isError && Object.keys(errors).length === 0 && <Alert>{errorMessage(reset.error)}</Alert>}
            <Button type="submit" className="w-full" loading={reset.isPending} disabled={!isStrongPassword(password) || password !== confirmation}>
              Enregistrer
            </Button>
          </form>
        )}
      </div>
    </div>
  )
}
