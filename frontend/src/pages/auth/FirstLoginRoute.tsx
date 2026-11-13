import { Navigate } from 'react-router-dom'
import { useAuth } from '../../auth/useAuth'
import { Spinner } from '../../components/ui'
import ChangePasswordPage from './ChangePasswordPage'

/** Écran de première connexion : seulement si l'administration a demandé un changement de mot de passe. */
export default function FirstLoginRoute() {
  const { user, isLoading } = useAuth()

  if (isLoading) return <Spinner />
  if (!user) return <Navigate to="/connexion" replace />
  if (!user.must_change_password) return <Navigate to="/" replace />

  return <ChangePasswordPage />
}
