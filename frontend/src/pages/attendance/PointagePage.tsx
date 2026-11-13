import { useMutation, useQuery } from '@tanstack/react-query'
import { LogIn, LogOut, MapPin, Plus, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { api, errorMessage, validationErrors } from '../../api/client'
import type { AttendanceRequest, TodayStatus } from '../../api/types'
import { useRefreshAttendance } from '../../api/useRefreshAttendance'
import { useAuth } from '../../auth/useAuth'
import { AttendanceRequestList, MonthPicker, TimesheetView } from '../../components/attendance'
import { Alert, Badge, Button, Card, Field, Input, Modal, PageHeader, Spinner } from '../../components/ui'
import { formatMinutes, presenceLabels, toIsoDate } from '../../lib/labels'

/** Position GPS si le navigateur l'autorise (facultatif, jamais bloquant). */
function getPosition(): Promise<{ latitude: number; longitude: number } | null> {
  if (!navigator.geolocation) return Promise.resolve(null)
  return new Promise((resolve) => {
    navigator.geolocation.getCurrentPosition(
      (p) => resolve({ latitude: Number(p.coords.latitude.toFixed(6)), longitude: Number(p.coords.longitude.toFixed(6)) }),
      () => resolve(null),
      { timeout: 4000, maximumAge: 60_000 },
    )
  })
}

function LiveClock() {
  const [now, setNow] = useState(() => new Date())
  useEffect(() => {
    const t = setInterval(() => setNow(new Date()), 1000)
    return () => clearInterval(t)
  }, [])
  return (
    <div className="text-center">
      <div className="text-5xl font-semibold text-slate-900 tabular-nums">{now.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' })}</div>
      <div className="mt-1 text-sm text-slate-500 capitalize">{now.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })}</div>
    </div>
  )
}

/** PRS-01 : pointage de l'employé. */
function ClockCard() {
  const refresh = useRefreshAttendance()
  const { data, isLoading } = useQuery({
    queryKey: ['attendance', 'me'],
    queryFn: async () => (await api.get<TodayStatus>('/attendance/me')).data,
    refetchInterval: 60_000,
  })
  const clock = useMutation({
    mutationFn: async (direction: 'in' | 'out') => {
      const position = direction === 'in' ? await getPosition() : null
      return (await api.post<TodayStatus>(`/attendance/clock-${direction}`, position ?? {})).data
    },
    onSuccess: refresh,
  })

  if (isLoading || !data) return <Spinner />
  const open = data.segments.some((s) => s.out === null)

  return (
    <Card>
      <div className="flex flex-col items-center gap-5 py-2">
        <LiveClock />
        <Badge className={presenceLabels[data.status].className}>{presenceLabels[data.status].label}</Badge>

        <Button className="h-14 w-64 text-base" variant={open ? 'danger' : 'primary'} loading={clock.isPending} onClick={() => clock.mutate(open ? 'out' : 'in')}>
          {open ? <LogOut className="h-5 w-5" /> : <LogIn className="h-5 w-5" />}
          {open ? 'Pointer mon départ' : 'Pointer mon arrivée'}
        </Button>
        {clock.isError && <Alert>{validationErrors(clock.error).clock?.[0] ?? errorMessage(clock.error)}</Alert>}

        <div className="w-full max-w-md space-y-2 text-sm">
          {data.planned_start ? (
            <p className="text-center text-slate-500">
              Horaire du jour : {data.planned_start} – {data.planned_end} ({formatMinutes(data.planned)})
            </p>
          ) : (
            <p className="text-center text-slate-500">{data.holiday ?? (data.leave ? data.leave.name : 'Pas de travail prévu aujourd’hui')}</p>
          )}
          {data.segments.length > 0 && (
            <ul className="divide-y divide-slate-100 rounded-md border border-slate-200">
              {data.segments.map((s) => (
                <li key={s.id} className="flex justify-between px-3 py-1.5">
                  <span>
                    {s.in} → {s.out ?? <span className="text-emerald-700">en cours</span>}
                  </span>
                </li>
              ))}
              <li className="flex justify-between bg-slate-50 px-3 py-1.5 font-medium">
                <span>Temps travaillé</span>
                <span>{formatMinutes(data.worked)}</span>
              </li>
            </ul>
          )}
          {data.late > 0 && <p className="text-center text-amber-700">Arrivée avec {data.late} min de retard.</p>}
        </div>
        <p className="flex items-center gap-1 text-xs text-slate-400">
          <MapPin className="h-3.5 w-3.5" /> Votre position est enregistrée à l'arrivée si votre navigateur l'autorise.
        </p>
      </div>
    </Card>
  )
}

function OvertimeModal({ onClose }: { onClose: () => void }) {
  const refresh = useRefreshAttendance()
  const [today] = useState(() => toIsoDate(new Date()))
  const [form, setForm] = useState(() => ({ date: today, hours: '1', minutes: '0', reason: '' }))
  const save = useMutation({
    mutationFn: () => api.post('/overtime-requests', { date: form.date, minutes: Number(form.hours) * 60 + Number(form.minutes), reason: form.reason }),
    onSuccess: () => {
      refresh()
      onClose()
    },
  })
  const errors = validationErrors(save.error)

  return (
    <Modal open title="Déclarer des heures supplémentaires" onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        <Field label="Jour" required error={errors.date?.[0]}>
          <Input type="date" max={today} value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} />
        </Field>
        <Field label="Durée" required error={errors.minutes?.[0]}>
          <div className="flex items-center gap-2">
            <Input type="number" min={0} max={12} className="w-20" value={form.hours} onChange={(e) => setForm({ ...form, hours: e.target.value })} /> h
            <Input type="number" min={0} max={59} step={5} className="w-20" value={form.minutes} onChange={(e) => setForm({ ...form, minutes: e.target.value })} /> min
          </div>
        </Field>
        <Field label="Motif" required error={errors.reason?.[0]}>
          <Input value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} placeholder="ex. livraison urgente d'un client" />
        </Field>
        {save.isError && Object.keys(errors).length === 0 && <Alert>{errorMessage(save.error)}</Alert>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>
            Annuler
          </Button>
          <Button type="submit" loading={save.isPending}>
            Envoyer à mon manager
          </Button>
        </div>
      </form>
    </Modal>
  )
}

/** PRS-05 : demande de correction (oubli de pointage, erreur). Aussi utilisé par les RH pour saisir. */
export function CorrectionModal({ employeeId, onClose }: { employeeId?: number; onClose: () => void }) {
  const refresh = useRefreshAttendance()
  const [today] = useState(() => toIsoDate(new Date()))
  const [date, setDate] = useState(today)
  const [segments, setSegments] = useState([
    { in: '09:00', out: '12:30' },
    { in: '13:30', out: '17:00' },
  ])
  const [reason, setReason] = useState('')
  const save = useMutation({
    mutationFn: () => api.post('/attendance-corrections', { employee_id: employeeId, date, segments, reason }),
    onSuccess: () => {
      refresh()
      onClose()
    },
  })
  const errors = validationErrors(save.error)
  const segmentError = Object.entries(errors).find(([k]) => k.startsWith('segments'))?.[1][0]

  return (
    <Modal open title={employeeId ? 'Saisir les horaires d’une journée' : 'Corriger un pointage'} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        <Field label="Jour" required error={errors.date?.[0]}>
          <Input type="date" max={today} value={date} onChange={(e) => setDate(e.target.value)} />
        </Field>
        <Field label="Horaires réels de la journée" required error={segmentError}>
          <div className="space-y-2">
            {segments.map((s, i) => (
              <div key={i} className="flex items-center gap-2">
                <Input type="time" className="w-32" value={s.in} onChange={(e) => setSegments(segments.map((x, j) => (j === i ? { ...x, in: e.target.value } : x)))} />
                →
                <Input type="time" className="w-32" value={s.out} onChange={(e) => setSegments(segments.map((x, j) => (j === i ? { ...x, out: e.target.value } : x)))} />
                {segments.length > 1 && (
                  <button type="button" className="p-1 text-slate-400 hover:text-rose-600" onClick={() => setSegments(segments.filter((_, j) => j !== i))} aria-label="Retirer">
                    <Trash2 className="h-4 w-4" />
                  </button>
                )}
              </div>
            ))}
            {segments.length < 6 && (
              <button type="button" className="text-xs text-brand-700 hover:underline" onClick={() => setSegments([...segments, { in: '', out: '' }])}>
                <Plus className="inline h-3.5 w-3.5" /> Ajouter une plage
              </button>
            )}
          </div>
        </Field>
        <Field label="Motif" required error={errors.reason?.[0]}>
          <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="ex. oubli de pointage au retour du déjeuner" />
        </Field>
        <p className="text-xs text-slate-500">
          {employeeId ? 'Saisie RH : les pointages de ce jour sont remplacés immédiatement.' : 'Après validation par votre manager, ces horaires remplacent les pointages de la journée.'}
        </p>
        {save.isError && Object.keys(errors).length === 0 && <Alert>{errorMessage(save.error)}</Alert>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>
            Annuler
          </Button>
          <Button type="submit" loading={save.isPending}>
            {employeeId ? 'Enregistrer' : 'Envoyer la demande'}
          </Button>
        </div>
      </form>
    </Modal>
  )
}

export default function PointagePage() {
  const { user } = useAuth()
  const [month, setMonth] = useState(() => toIsoDate(new Date()).slice(0, 7))
  const [modal, setModal] = useState<'overtime' | 'correction' | null>(null)

  const overtime = useQuery({
    queryKey: ['overtime-requests', 'mine'],
    queryFn: async () => (await api.get<AttendanceRequest[]>('/overtime-requests?scope=mine')).data,
  })
  const corrections = useQuery({
    queryKey: ['attendance-corrections', 'mine'],
    queryFn: async () => (await api.get<AttendanceRequest[]>('/attendance-corrections?scope=mine')).data,
  })

  if (!user?.employee_id) return <Alert>Votre compte n'est rattaché à aucun dossier employé.</Alert>

  return (
    <>
      <PageHeader
        title="Pointage"
        actions={
          <>
            <Button variant="secondary" onClick={() => setModal('correction')}>
              Corriger un pointage
            </Button>
            <Button variant="secondary" onClick={() => setModal('overtime')}>
              Déclarer des heures sup.
            </Button>
          </>
        }
      />
      <div className="grid gap-6 lg:grid-cols-[22rem_1fr]">
        <ClockCard />
        <div className="space-y-6">
          <Card title="Mes heures supplémentaires">
            <AttendanceRequestList kind="overtime" requests={overtime.data ?? []} emptyText="Aucune déclaration." />
          </Card>
          <Card title="Mes demandes de correction">
            <AttendanceRequestList kind="correction" requests={corrections.data ?? []} emptyText="Aucune demande." />
          </Card>
        </div>
      </div>

      <Card title="Ma feuille de temps" className="mt-6" actions={<MonthPicker value={month} onChange={setMonth} />}>
        <TimesheetView month={month} />
      </Card>

      {modal === 'overtime' && <OvertimeModal onClose={() => setModal(null)} />}
      {modal === 'correction' && <CorrectionModal onClose={() => setModal(null)} />}
    </>
  )
}
