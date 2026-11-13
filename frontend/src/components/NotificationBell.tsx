import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bell, CalendarCheck, CalendarDays, Clock, FileClock, FileText, Settings } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { api } from '../api/client'
import type { AppNotification, NotificationList } from '../api/types'

const icons: Record<AppNotification['category'], typeof Bell> = {
  leave_to_approve: CalendarCheck,
  leave_decision: CalendarDays,
  contract_deadline: FileClock,
  documents: FileText,
  attendance: Clock,
}

/** « il y a 5 min », « hier », « 12/10/2026 » */
function timeAgo(iso: string): string {
  const minutes = Math.round((Date.now() - new Date(iso).getTime()) / 60_000)
  if (minutes < 1) return "à l'instant"
  if (minutes < 60) return `il y a ${minutes} min`
  const hours = Math.round(minutes / 60)
  if (hours < 24) return `il y a ${hours} h`
  if (hours < 48) return 'hier'
  return new Date(iso).toLocaleDateString('fr-FR')
}

/** NOT-01 : cloche avec le nombre de notifications non lues, rafraîchie toutes les 30 secondes. */
export default function NotificationBell({ align = 'right' }: { align?: 'left' | 'right' }) {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const panel = useRef<HTMLDivElement>(null)

  const { data } = useQuery({
    queryKey: ['notifications'],
    queryFn: async () => (await api.get<NotificationList>('/notifications')).data,
    refetchInterval: 30_000,
  })

  // Une nouvelle notification arrive : on rafraîchit aussi les écrans de congés
  const unread = data?.unread_count ?? 0
  const previous = useRef(unread)
  useEffect(() => {
    if (unread > previous.current) {
      for (const key of ['leave-requests', 'leave-pending-count', 'leave-balances', 'contract-alerts', 'document-requests', 'company-documents', 'documents', 'attendance', 'overtime-requests', 'attendance-corrections']) {
        queryClient.invalidateQueries({ queryKey: [key] })
      }
    }
    previous.current = unread
  }, [unread, queryClient])

  // Fermeture au clic à l'extérieur
  useEffect(() => {
    if (!open) return
    const close = (e: MouseEvent) => panel.current && !panel.current.contains(e.target as Node) && setOpen(false)
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [open])

  const refresh = (r: { data: { unread_count: number } }) => {
    queryClient.setQueryData<NotificationList>(['notifications'], (old) => old && { ...old, unread_count: r.data.unread_count })
    queryClient.invalidateQueries({ queryKey: ['notifications'] })
  }
  const markRead = useMutation({ mutationFn: (id: string) => api.post(`/notifications/${id}/read`), onSuccess: refresh })
  const markAll = useMutation({ mutationFn: () => api.post('/notifications/read-all'), onSuccess: refresh })

  const openNotification = (n: AppNotification) => {
    if (!n.read) markRead.mutate(n.id)
    setOpen(false)
    navigate(n.url)
  }

  return (
    <div className="relative" ref={panel}>
      <button
        onClick={() => setOpen(!open)}
        className="relative rounded-full p-2 text-slate-600 hover:bg-slate-100"
        aria-label={`Notifications${unread ? ` (${unread} non lues)` : ''}`}
      >
        <Bell className="h-5 w-5" />
        {unread > 0 && (
          <span className="absolute -top-0.5 -right-0.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-600 px-1 text-[10px] font-bold text-white">
            {unread > 99 ? '99+' : unread}
          </span>
        )}
      </button>

      {open && (
        <div className={`absolute z-50 mt-2 w-[22rem] max-w-[calc(100vw-2rem)] rounded-lg border border-slate-200 bg-white shadow-xl ${align === 'right' ? 'right-0' : 'left-0'}`}>
          <header className="flex items-center justify-between border-b border-slate-100 px-4 py-3">
            <span className="font-semibold">Notifications</span>
            <div className="flex items-center gap-3 text-xs">
              {unread > 0 && (
                <button className="text-brand-700 hover:underline" onClick={() => markAll.mutate()}>
                  Tout marquer comme lu
                </button>
              )}
              <Link to="/notifications/preferences" onClick={() => setOpen(false)} className="text-slate-400 hover:text-slate-600" title="Préférences">
                <Settings className="h-4 w-4" />
              </Link>
            </div>
          </header>
          <ul className="max-h-[26rem] divide-y divide-slate-100 overflow-y-auto">
            {data?.data.map((n) => {
              const Icon = icons[n.category] ?? Bell
              return (
                <li key={n.id}>
                  <button onClick={() => openNotification(n)} className={`flex w-full gap-3 px-4 py-3 text-left hover:bg-slate-50 ${n.read ? '' : 'bg-brand-50/60'}`}>
                    <Icon className={`mt-0.5 h-4 w-4 shrink-0 ${n.read ? 'text-slate-400' : 'text-brand-700'}`} />
                    <span className="min-w-0">
                      <span className={`block text-sm ${n.read ? 'text-slate-700' : 'font-medium text-slate-900'}`}>{n.title}</span>
                      <span className="mt-0.5 line-clamp-2 block text-xs text-slate-500">{n.message}</span>
                      <span className="mt-1 block text-[11px] text-slate-400">{timeAgo(n.created_at)}</span>
                    </span>
                    {!n.read && <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-brand-500" />}
                  </button>
                </li>
              )
            })}
            {data?.data.length === 0 && <li className="px-4 py-8 text-center text-sm text-slate-500">Aucune notification.</li>}
          </ul>
        </div>
      )}
    </div>
  )
}
