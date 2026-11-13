import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api, errorMessage, validationErrors } from '../../api/client'
import { Alert, Button, Field, Input } from '../../components/ui'

/** AUTH-02 : demande d'un lien de réinitialisation, envoyé par e-mail (valable 30 minutes). */
export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const send = useMutation({ mutationFn: async () => (await api.post<{ message: string }>('/forgot-password', { email })).data })

  return (
    <div className="flex min-h-screen items-center justify-center bg-gradient-to-br from-brand-700 to-brand-500 p-4">
      <div className="w-full max-w-sm rounded-xl bg-white p-8 shadow-xl">
        <h1 className="text-xl font-semibold text-slate-900">Mot de passe oublié</h1>
        {send.isSuccess ? (
          <div className="mt-5 space-y-4">
            <Alert tone="success">{send.data.message}</Alert>
            <p className="text-sm text-slate-600">Le lien est valable 30 minutes. Pensez à vérifier vos courriers indésirables.</p>
          </div>
        ) : (
          <form
            className="mt-5 space-y-4"
            onSubmit={(e) => {
              e.preventDefault()
              send.mutate()
            }}
          >
            <p className="text-sm text-slate-600">Saisissez votre e-mail professionnel : vous recevrez un lien pour choisir un nouveau mot de passe.</p>
            <Field label="E-mail" error={validationErrors(send.error).email?.[0]}>
              <Input type="email" autoComplete="username" autoFocus value={email} onChange={(e) => setEmail(e.target.value)} />
            </Field>
            {send.isError && Object.keys(validationErrors(send.error)).length === 0 && <Alert>{errorMessage(send.error)}</Alert>}
            <Button type="submit" className="w-full" loading={send.isPending} disabled={!email}>
              Envoyer le lien
            </Button>
          </form>
        )}
        <Link to="/connexion" className="mt-5 block text-center text-sm text-brand-700 hover:underline">
          Retour à la connexion
        </Link>
      </div>
    </div>
  )
}
