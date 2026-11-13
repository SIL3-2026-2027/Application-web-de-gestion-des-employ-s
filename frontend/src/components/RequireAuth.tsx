import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import type { Role } from '../api/types'
import { useAuth } from '../auth/useAuth'
import { Spinner } from './ui'

/** Garde de route : connexion obligatoire, et éventuellement un des rôles indiqués. */
export default function RequireAuth({ children, roles }: { children: ReactNode; roles?: Role[] }) {
  const { user, isLoading, hasRole } = useAuth()
  const location = useLocation()

  if (isLoading) return <Spinner />
  if (!user) return <Navigate to="/connexion" replace state={{ from: location.pathname }} />
  // Mot de passe saisi par l'administration : l'employé doit le remplacer avant toute chose
  if (user.must_change_password) return <Navigate to="/premiere-connexion" replace />
  if (roles && !hasRole(...roles)) return <Navigate to="/" replace />

  return <>{children}</>
}
