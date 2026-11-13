import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api, errorMessage, validationErrors } from '../../api/client'
import { useDepartments, useEmployeeOptions } from '../../api/queries'
import type { AttendanceRequest, TeamPresence, WorkSchedule } from '../../api/types'
import { useAuth } from '../../auth/useAuth'
import { AttendanceRequestList, MonthPicker, TimesheetView } from '../../components/attendance'
import { Alert, Badge, Button, Card, Field, Input, Modal, PageHeader, Select, Spinner } from '../../components/ui'
import { formatMinutes, presenceLabels, toIsoDate, WEEKDAYS_LONG } from '../../lib/labels'
import { CorrectionModal } from './PointagePage'

type Tab = 'today' | 'approvals' | 'timesheets' | 'schedules'

function TodayBoard() {
  const { hasRole } = useAuth()
  const [departmentId, setDepartmentId] = useState('')
  const { data: departments } = useDepartments()
  const { data, isLoading } = useQuery({
    queryKey: ['attendance', 'team', departmentId],
    queryFn: async () => (await api.get<TeamPresence[]>(`/attendance/team${departmentId ? `?department_id=${departmentId}` : ''}`)).data,
    refetchInterval: 60_000,
  })

  const counts = (data ?? []).reduce<Record<string, number>>((acc, p) => ({ ...acc, [p.status]: (acc[p.status] ?? 0) + 1 }), {})

  return (
    <Card
      title="Présences du jour"
      actions={
        hasRole('super-admin', 'rh', 'direction') && (
          <Select value={departmentId} onChange={(e) => setDepartmentId(e.target.value)} className="w-56">
            <option value="">Tous les départements</option>
            {departments?.map((d) => (
              <option key={d.id} value={d.id}>
                {d.name}
              </option>
            ))}
          </Select>
        )
      }
    >
      <div className="mb-4 flex flex-wrap gap-2">
        {(['present', 'non_arrive', 'conge', 'parti', 'attendu', 'repos'] as const).map(
          (s) =>
            counts[s] > 0 && (
              <Badge key={s} className={presenceLabels[s].className}>
                {presenceLabels[s].label} : {counts[s]}
              </Badge>
            ),
        )}
      </div>
      {isLoading ? (
        <Spinner />
      ) : (
        <ul className="divide-y divide-slate-100 text-sm">
          {data?.map((p) => (
            <li key={p.employee.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
              <div>
                <Link to={`/employes/${p.employee.id}`} className="font-medium text-brand-700 hover:underline">
                  {p.employee.full_name}
                </Link>
                <span className="ml-2 text-xs text-slate-500">{p.employee.position}</span>
              </div>
              <div className="flex items-center gap-3 text-xs text-slate-600">
                {p.segments.map((s) => (
                  <span key={s.in}>
                    {s.in} → {s.out ?? '…'}
                  </span>
                ))}
                {p.late > 0 && <span className="text-amber-700">retard {p.late} min</span>}
                {p.leave && <span>{p.leave.name}</span>}
                <Badge className={presenceLabels[p.status].className}>{presenceLabels[p.status].label}</Badge>
              </div>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

function Approvals() {
  const overtime = useQuery({
    queryKey: ['overtime-requests', 'to_approve'],
    queryFn: async () => (await api.get<AttendanceRequest[]>('/overtime-requests?scope=to_approve')).data,
  })
  const corrections = useQuery({
    queryKey: ['attendance-corrections', 'to_approve'],
    queryFn: async () => (await api.get<AttendanceRequest[]>('/attendance-corrections?scope=to_approve')).data,
  })

  return (
    <div className="grid gap-6 xl:grid-cols-2">
      <Card title="Heures supplémentaires à valider">
        {overtime.isLoading ? <Spinner /> : <AttendanceRequestList kind="overtime" requests={overtime.data ?? []} showEmployee emptyText="Rien à valider." />}
      </Card>
      <Card title="Corrections de pointage à valider">
        {corrections.isLoading ? <Spinner /> : <AttendanceRequestList kind="correction" requests={corrections.data ?? []} showEmployee emptyText="Rien à valider." />}
      </Card>
    </div>
  )
}

function Timesheets() {
  const { isHr } = useAuth()
  const { data: employees } = useEmployeeOptions()
  const [employeeId, setEmployeeId] = useState('')
  const [month, setMonth] = useState(() => toIsoDate(new Date()).slice(0, 7))
  const [entering, setEntering] = useState(false)
  const selected = employeeId || (employees?.[0] ? String(employees[0].id) : '')

  return (
    <Card
      title="Feuilles de temps"
      actions={
        <div className="flex flex-wrap items-center gap-2">
          <Select value={selected} onChange={(e) => setEmployeeId(e.target.value)} className="w-64">
            {employees?.map((e) => (
              <option key={e.id} value={e.id}>
                {e.label}
              </option>
            ))}
          </Select>
          <MonthPicker value={month} onChange={setMonth} />
          {isHr && selected && (
            <Button variant="secondary" onClick={() => setEntering(true)}>
              Saisir une journée
            </Button>
          )}
        </div>
      }
    >
      {selected ? <TimesheetView employeeId={Number(selected)} month={month} /> : <p className="text-sm text-slate-500">Aucun employé.</p>}
      {entering && <CorrectionModal employeeId={Number(selected)} onClose={() => setEntering(false)} />}
    </Card>
  )
}

const emptySchedule = { name: '', late_tolerance: 10, is_default: false, days: Object.fromEntries([1, 2, 3, 4, 5].map((d) => [d, { start: '09:00', end: '17:00', break: 60 }])) }

function Schedules() {
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState<(Partial<WorkSchedule> & typeof emptySchedule) | null>(null)
  const { data, isLoading } = useQuery({
    queryKey: ['attendance', 'schedules'],
    queryFn: async () => (await api.get<WorkSchedule[]>('/work-schedules')).data,
  })
  const remove = useMutation({
    mutationFn: (id: number) => api.delete(`/work-schedules/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['attendance'] }),
  })

  return (
    <Card
      title="Horaires de travail"
      actions={
        <Button variant="secondary" onClick={() => setEditing({ ...emptySchedule })}>
          <Plus className="h-4 w-4" /> Nouvel horaire
        </Button>
      }
    >
      <p className="mb-3 text-sm text-slate-500">L'horaire par défaut s'applique aux employés sans horaire particulier (à choisir dans leur fiche, étape « Poste »).</p>
      {remove.isError && <Alert>{errorMessage(remove.error)}</Alert>}
      {isLoading ? (
        <Spinner />
      ) : (
        <ul className="divide-y divide-slate-100 text-sm">
          {data?.map((s) => (
            <li key={s.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
              <div>
                <span className="font-medium">{s.name}</span>
                {s.is_default && <Badge className="ml-2 bg-brand-50 text-brand-700">Par défaut</Badge>}
                <div className="text-xs text-slate-500">
                  {Object.keys(s.days)
                    .map((d) => WEEKDAYS_LONG[Number(d)].slice(0, 3))
                    .join(', ')}{' '}
                  · {formatMinutes(s.weekly_minutes)} / semaine · tolérance {s.late_tolerance} min · {s.employees_count} employé(s) rattaché(s)
                </div>
              </div>
              <div>
                <button className="p-1 text-slate-400 hover:text-brand-700" aria-label="Modifier" onClick={() => setEditing({ ...s })}>
                  <Pencil className="h-4 w-4" />
                </button>
                <button className="p-1 text-slate-400 hover:text-rose-600" aria-label="Supprimer" onClick={() => confirm(`Supprimer « ${s.name} » ?`) && remove.mutate(s.id)}>
                  <Trash2 className="h-4 w-4" />
                </button>
              </div>
            </li>
          ))}
        </ul>
      )}
      {editing && <ScheduleModal initial={editing} onClose={() => setEditing(null)} />}
    </Card>
  )
}

function ScheduleModal({ initial, onClose }: { initial: Partial<WorkSchedule> & typeof emptySchedule; onClose: () => void }) {
  const queryClient = useQueryClient()
  const [form, setForm] = useState(initial)
  const save = useMutation({
    mutationFn: () => (form.id ? api.put(`/work-schedules/${form.id}`, form) : api.post('/work-schedules', form)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['attendance'] })
      onClose()
    },
  })
  const errors = validationErrors(save.error)
  const setDay = (d: number, value: { start: string; end: string; break: number } | null) => {
    const days = { ...form.days }
    if (value) days[d] = value
    else delete days[d]
    setForm({ ...form, days })
  }

  return (
    <Modal open title={form.id ? 'Modifier l’horaire' : 'Nouvel horaire'} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        <Field label="Nom" required error={errors.name?.[0]}>
          <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="ex. Équipe du matin" />
        </Field>
        <div className="space-y-1.5">
          {[1, 2, 3, 4, 5, 6, 7].map((d) => {
            const day = form.days[d]
            const error = errors[`days.${d}.end`]?.[0] ?? errors[`days.${d}.start`]?.[0]
            return (
              <div key={d}>
                <div className="flex flex-wrap items-center gap-2 text-sm">
                  <label className="flex w-28 items-center gap-2">
                    <input type="checkbox" checked={!!day} onChange={(e) => setDay(d, e.target.checked ? { start: '09:00', end: '17:00', break: 60 } : null)} />
                    {WEEKDAYS_LONG[d]}
                  </label>
                  {day && (
                    <>
                      <Input type="time" className="w-28" value={day.start} onChange={(e) => setDay(d, { ...day, start: e.target.value })} />
                      –
                      <Input type="time" className="w-28" value={day.end} onChange={(e) => setDay(d, { ...day, end: e.target.value })} />
                      <span className="text-slate-500">pause</span>
                      <Input type="number" min={0} max={240} step={5} className="w-20" value={day.break} onChange={(e) => setDay(d, { ...day, break: Number(e.target.value) })} />
                      <span className="text-slate-500">min</span>
                    </>
                  )}
                </div>
                {error && <p className="ml-28 text-xs text-rose-600">{error}</p>}
              </div>
            )
          })}
          {errors.days && <p className="text-xs text-rose-600">{errors.days[0]}</p>}
        </div>
        <div className="flex flex-wrap items-center gap-6">
          <Field label="Tolérance de retard (min)" error={errors.late_tolerance?.[0]}>
            <Input type="number" min={0} max={120} className="w-24" value={form.late_tolerance} onChange={(e) => setForm({ ...form, late_tolerance: Number(e.target.value) })} />
          </Field>
          <label className="mt-5 flex items-center gap-2 text-sm">
            <input type="checkbox" checked={form.is_default} onChange={(e) => setForm({ ...form, is_default: e.target.checked })} /> Horaire par défaut
          </label>
        </div>
        {save.isError && Object.keys(errors).length === 0 && <Alert>{errorMessage(save.error)}</Alert>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>
            Annuler
          </Button>
          <Button type="submit" loading={save.isPending}>
            Enregistrer
          </Button>
        </div>
      </form>
    </Modal>
  )
}

/** Présences : vue d'équipe (manager), toute l'entreprise (RH, direction). */
export default function PresencesPage() {
  const { isHr, hasRole } = useAuth()
  const [tab, setTab] = useState<Tab>('today')
  const canApprove = hasRole('super-admin', 'rh', 'manager')

  const tabs: [Tab, string][] = [
    ['today', "Aujourd'hui"],
    ...(canApprove ? ([['approvals', 'À valider']] as [Tab, string][]) : []),
    ['timesheets', 'Feuilles de temps'],
    ...(isHr ? ([['schedules', 'Horaires']] as [Tab, string][]) : []),
  ]

  return (
    <>
      <PageHeader title="Présences" subtitle="Pointages, heures supplémentaires et feuilles de temps" />
      <div className="mb-4 flex gap-1 border-b border-slate-200">
        {tabs.map(([key, label]) => (
          <button
            key={key}
            onClick={() => setTab(key)}
            className={`-mb-px border-b-2 px-4 py-2 text-sm ${tab === key ? 'border-brand-700 font-medium text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700'}`}
          >
            {label}
          </button>
        ))}
      </div>
      {tab === 'today' && <TodayBoard />}
      {tab === 'approvals' && <Approvals />}
      {tab === 'timesheets' && <Timesheets />}
      {tab === 'schedules' && <Schedules />}
    </>
  )
}
