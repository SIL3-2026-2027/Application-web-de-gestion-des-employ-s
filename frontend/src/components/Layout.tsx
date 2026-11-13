import { useQuery } from '@tanstack/react-query'
import { Banknote, Building2, CalendarCheck, Clock, Timer, FolderCog, FolderOpen, KeyRound, UserCog, CalendarDays, CalendarRange, LayoutDashboard, LogOut, Menu, Settings2, UserCircle, Users, X } from 'lucide-react'
import { useState } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { api } from '../api/client'
import type { LeaveRequest, Paginated, Role } from '../api/types'
import { useAuth } from '../auth/useAuth'
import { roleLabels } from '../lib/labels'
import NotificationBell from './NotificationBell'

interface NavItem {
  to: string
  label: string
  icon: typeof Users
  roles?: Role[]
  needsEmployee?: boolean
  badge?: 'pending'
}

const nav: NavItem[] = [
  { to: '/', label: 'Tableau de bord', icon: LayoutDashboard },
  { to: '/employes', label: 'Employés', icon: Users, roles: ['super-admin', 'rh', 'manager', 'direction'] },
  { to: '/pointage', label: 'Pointage', icon: Timer, needsEmployee: true },
  { to: '/presences', label: 'Présences', icon: Clock, roles: ['super-admin', 'rh', 'manager', 'direction'] },
  { to: '/conges', label: 'Mes congés', icon: CalendarDays, needsEmployee: true },
  { to: '/conges/validations', label: 'Validation des congés', icon: CalendarCheck, roles: ['super-admin', 'rh', 'manager'], badge: 'pending' },
  { to: '/conges/calendrier', label: 'Calendrier des absences', icon: CalendarRange, roles: ['super-admin', 'rh', 'manager', 'direction'] },
  { to: '/paie', label: 'Paie', icon: Banknote, roles: ['super-admin', 'rh'] },
  { to: '/documents', label: 'Mes documents', icon: FolderOpen },
  { to: '/documents/administration', label: 'Gestion des documents', icon: FolderCog, roles: ['super-admin', 'rh'] },
  { to: '/organisation', label: 'Organisation', icon: Building2, roles: ['super-admin', 'rh'] },
  { to: '/conges/parametres', label: 'Paramètres congés', icon: Settings2, roles: ['super-admin', 'rh'] },
  { to: '/administration/comptes', label: 'Comptes utilisateurs', icon: UserCog, roles: ['super-admin', 'rh'] },
]

export default function Layout() {
  const { user, logout, hasRole } = useAuth()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)

  const items = nav.filter((i) => (!i.roles || hasRole(...i.roles)) && (!i.needsEmployee || user?.employee_id))
  const canApprove = hasRole('super-admin', 'rh', 'manager')

  // Nombre de demandes en attente de ma validation, rafraîchi toutes les minutes
  const { data: pendingCount } = useQuery({
    queryKey: ['leave-pending-count'],
    queryFn: async () => (await api.get<Paginated<LeaveRequest>>('/leave-requests?scope=to_approve')).data.meta.total,
    enabled: canApprove,
    refetchInterval: 60_000,
  })

  const sidebar = (
    <nav className="flex h-full flex-col bg-brand-700 text-white">
      <div className="px-5 py-5 text-lg font-semibold">Gestion RH</div>
      <ul className="flex-1 space-y-1 px-3">
        {items.map(({ to, label, icon: Icon, badge }) => (
          <li key={to}>
            <NavLink
              to={to}
              end={to === '/' || to === '/conges' || to === '/documents'}
              onClick={() => setOpen(false)}
              className={({ isActive }) =>
                `flex items-center gap-3 rounded-md px-3 py-2 text-sm ${isActive ? 'bg-white/15 font-medium' : 'text-blue-100 hover:bg-white/10'}`
              }
            >
              <Icon className="h-4 w-4" />
              <span className="flex-1">{label}</span>
              {badge === 'pending' && !!pendingCount && (
                <span className="rounded-full bg-amber-400 px-2 text-xs font-semibold text-amber-950">{pendingCount}</span>
              )}
            </NavLink>
          </li>
        ))}
        {user?.employee_id && (
          <li>
            <NavLink
              to={`/employes/${user.employee_id}`}
              onClick={() => setOpen(false)}
              className="flex items-center gap-3 rounded-md px-3 py-2 text-sm text-blue-100 hover:bg-white/10"
            >
              <UserCircle className="h-4 w-4" />
              Mon dossier
            </NavLink>
          </li>
        )}
      </ul>
      <div className="border-t border-white/15 p-4 text-sm">
        <div className="font-medium">{user?.name}</div>
        <div className="text-xs text-blue-200">{user?.roles.map((r) => roleLabels[r]).join(', ')}</div>
        <NavLink to="/mon-compte/mot-de-passe" onClick={() => setOpen(false)} className="mt-3 flex items-center gap-2 text-blue-100 hover:text-white">
          <KeyRound className="h-4 w-4" /> Mon mot de passe
        </NavLink>
        <button
          onClick={() => logout.mutate(undefined, { onSettled: () => navigate('/connexion') })}
          className="mt-2 flex items-center gap-2 text-blue-100 hover:text-white"
        >
          <LogOut className="h-4 w-4" /> Déconnexion
        </button>
      </div>
    </nav>
  )

  return (
    <div className="flex min-h-screen">
      <aside className="hidden w-60 shrink-0 md:block">{sidebar}</aside>

      {open && (
        <div className="fixed inset-0 z-40 md:hidden">
          <div className="absolute inset-0 bg-slate-900/40" onClick={() => setOpen(false)} />
          <aside className="relative h-full w-64">{sidebar}</aside>
        </div>
      )}

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex items-center gap-3 border-b border-slate-200 bg-white px-4 py-2 md:justify-end md:px-8">
          <button onClick={() => setOpen(!open)} aria-label="Menu" className="md:hidden">
            {open ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
          </button>
          <span className="flex-1 font-semibold md:hidden">Gestion RH</span>
          <NotificationBell />
        </header>
        <main className="flex-1 p-4 md:p-8">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
