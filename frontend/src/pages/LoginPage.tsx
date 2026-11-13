import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom'
import { z } from 'zod'
import { errorMessage, validationErrors } from '../api/client'
import { useAuth } from '../auth/useAuth'
import { Alert, Button, Field, Input } from '../components/ui'

const schema = z.object({
  email: z.email('Adresse e-mail invalide'),
  password: z.string().min(1, 'Mot de passe requis'),
  remember: z.boolean().optional(),
})
type FormData = z.infer<typeof schema>

export default function LoginPage() {
  const { user, login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const from = (location.state as { from?: string } | null)?.from ?? '/'

  const { register, handleSubmit, formState } = useForm<FormData>({ resolver: zodResolver(schema) })

  if (user) return <Navigate to={from} replace />

  const onSubmit = (data: FormData) => login.mutate(data, { onSuccess: () => navigate(from, { replace: true }) })

  const serverError = login.error ? (validationErrors(login.error).email?.[0] ?? errorMessage(login.error)) : null

  return (
    <div className="flex min-h-screen items-center justify-center bg-linear-to-br from-brand-700 to-brand-500 p-4">
      <form onSubmit={handleSubmit(onSubmit)} className="w-full max-w-sm space-y-5 rounded-xl bg-white p-8 shadow-xl">
        <div className="text-center">
          <h1 className="text-2xl font-semibold text-slate-900">Gestion RH</h1>
          <p className="mt-1 text-sm text-slate-500">Connectez-vous avec les identifiants remis par l'administration</p>
        </div>

        {serverError && <Alert>{serverError}</Alert>}

        <Field label="E-mail" error={formState.errors.email?.message}>
          <Input type="email" autoComplete="username" autoFocus {...register('email')} />
        </Field>
        <Field label="Mot de passe" error={formState.errors.password?.message}>
          <Input type="password" autoComplete="current-password" {...register('password')} />
        </Field>
        <div className="flex items-center justify-between text-sm">
          <label className="flex items-center gap-2 text-slate-600">
            <input type="checkbox" {...register('remember')} /> Rester connecté
          </label>
          <Link to="/mot-de-passe-oublie" className="text-brand-700 hover:underline">
            Mot de passe oublié ?
          </Link>
        </div>

        <Button type="submit" className="w-full" loading={login.isPending}>
          Se connecter
        </Button>
      </form>
    </div>
  )
}
