import { useMutation, useQuery } from '@tanstack/react-query'
import { Check, ChevronLeft, ChevronRight, X } from 'lucide-react'
import { useState } from 'react'
import { api, errorMessage, validationErrors } from '../api/client'
import type { AttendanceRequest, Timesheet } from '../api/types'
import { useRefreshAttendance } from '../api/useRefreshAttendance'
import { anomalyLabels, formatDate, formatMinutes, WEEKDAYS_SHORT } from '../lib/labels'
import { Alert, Badge, Button, Field, Input, Modal, Spinner } from './ui'

export function MonthPicker({ value, onChange }: { value: string; onChange: (month: string) => void }) {
  const [y, m] = value.split('-').map(Number)
  const shift = (n: number) => {
    const d = new Date(y, m - 1 + n, 1)
    onChange(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`)
  }
  return (
    <div className="flex items-center gap-1">
      <Button variant="secondary" className="px-2" onClick={() => shift(-1)} aria-label="Mois précédent">
        <ChevronLeft className="h-4 w-4" />
      </Button>
      <span className="w-36 text-center font-medium capitalize">{new Date(y, m - 1, 1).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })}</span>
      <Button variant="secondary" className="px-2" onClick={() => shift(1)} aria-label="Mois suivant">
        <ChevronRight className="h-4 w-4" />
      </Button>
    </div>
  )
}

/** PRS-03 : feuille de temps mensuelle, avec totaux par semaine. */
export function TimesheetView({ employeeId, month }: { employeeId?: number; month: string }) {
  const { data, isLoading } = useQuery({
    queryKey: ['attendance', 'timesheet', employeeId ?? 'me', month],
    queryFn: async () => (await api.get<Timesheet>(`/attendance/timesheet?month=${month}${employeeId ? `&employee_id=${employeeId}` : ''}`)).data,
  })

  if (isLoading || !data) return <Spinner />

  const t = data.totals
  const balance = t.worked - t.planned

  // Regroupement par semaine (lundi → dimanche)
  const weeks: (typeof data.days)[] = []
  for (const day of data.days) {
    if (day.weekday === 1 || weeks.length === 0) weeks.push([])
    weeks[weeks.length - 1].push(day)
  }

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
        {[
          ['Prévu', formatMinutes(t.planned)],
          ['Travaillé', formatMinutes(t.worked)],
          ['Écart', `${balance > 0 ? '+' : ''}${formatMinutes(balance)}`],
          ['Heures sup. validées', formatMinutes(t.overtime_approved)],
          ['Anomalies', `${t.absences} abs. · ${t.late_count} ret. · ${t.missing_out} oubli`],
        ].map(([label, value]) => (
          <div key={label} className="rounded-md border border-slate-200 bg-white px-3 py-2">
            <div className="text-xs text-slate-500">{label}</div>
            <div className="font-semibold">{value}</div>
          </div>
        ))}
      </div>
      {data.schedule ? (
        <p className="text-xs text-slate-500">Horaire : {data.schedule.name} ({formatMinutes(data.schedule.weekly_minutes)} par semaine)</p>
      ) : (
        <Alert>Aucun horaire défini : les heures prévues et les retards ne peuvent pas être calculés.</Alert>
      )}

      <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <table className="min-w-full text-sm">
          <thead className="bg-slate-50 text-left text-xs font-semibold text-slate-500 uppercase">
            <tr>
              <th className="px-3 py-2">Jour</th>
              <th className="px-3 py-2">Prévu</th>
              <th className="px-3 py-2">Pointages</th>
              <th className="px-3 py-2 text-right">Travaillé</th>
              <th className="px-3 py-2 text-right">Heures sup.</th>
              <th className="px-3 py-2">Observations</th>
            </tr>
          </thead>
          {weeks.map((week, i) => {
            const worked = week.reduce((s, d) => s + d.worked, 0)
            const planned = week.reduce((s, d) => s + d.planned, 0)
            return (
              <tbody key={i} className="divide-y divide-slate-100 border-b-2 border-slate-200">
                {week.map((d) => {
                  const off = d.planned === 0 && !d.leave && d.segments.length === 0
                  return (
                    <tr key={d.date} className={off ? 'bg-slate-50 text-slate-400' : ''}>
                      <td className="px-3 py-1.5 whitespace-nowrap">
                        {WEEKDAYS_SHORT[d.weekday]} {formatDate(d.date).slice(0, 5)}
                      </td>
                      <td className="px-3 py-1.5 whitespace-nowrap">{d.planned_start ? `${d.planned_start}–${d.planned_end}` : '—'}</td>
                      <td className="px-3 py-1.5">
                        <div className="flex flex-wrap gap-1">
                          {d.segments.map((s) => (
                            <span key={s.id} className={`rounded px-1.5 py-0.5 text-xs ${s.source === 'application' ? 'bg-slate-100' : 'bg-sky-50 text-sky-800'}`} title={s.source === 'application' ? undefined : 'Corrigé'}>
                              {s.in} → {s.out ?? '…'}
                            </span>
                          ))}
                        </div>
                      </td>
                      <td className="px-3 py-1.5 text-right whitespace-nowrap">{d.worked ? formatMinutes(d.worked) : ''}</td>
                      <td className="px-3 py-1.5 text-right whitespace-nowrap">
                        {d.overtime_approved > 0 && <span className="text-emerald-700">{formatMinutes(d.overtime_approved)}</span>}
                        {d.overtime_pending > 0 && <span className="text-amber-700"> ({formatMinutes(d.overtime_pending)} en attente)</span>}
                      </td>
                      <td className="px-3 py-1.5">
                        <div className="flex flex-wrap items-center gap-1">
                          {d.holiday && <Badge className="bg-slate-100 text-slate-600">{d.holiday}</Badge>}
                          {d.leave && (
                            <span className="rounded-full px-2.5 py-0.5 text-xs font-medium text-white" style={{ backgroundColor: d.leave.color }}>
                              {d.leave.name}
                              {d.leave.half_day ? ' (½ j)' : ''}
                            </span>
                          )}
                          {d.anomalies.map((a) => (
                            <Badge key={a} className={anomalyLabels[a].className}>
                              {anomalyLabels[a].label}
                              {a === 'retard' && ` ${d.late} min`}
                            </Badge>
                          ))}
                        </div>
                      </td>
                    </tr>
                  )
                })}
                <tr className="bg-slate-50/70 text-xs text-slate-600">
                  <td className="px-3 py-1" colSpan={3}>
                    Total de la semaine
                  </td>
                  <td className="px-3 py-1 text-right font-semibold">{formatMinutes(worked)}</td>
                  <td className="px-3 py-1 text-right" colSpan={2}>
                    sur {formatMinutes(planned)} prévues
                  </td>
                </tr>
              </tbody>
            )
          })}
        </table>
      </div>
    </div>
  )
}

/** Liste de demandes (heures sup. ou corrections) avec validation / refus. */
export function AttendanceRequestList({ kind, requests, showEmployee, emptyText }: { kind: 'overtime' | 'correction'; requests: AttendanceRequest[]; showEmployee?: boolean; emptyText: string }) {
  const refresh = useRefreshAttendance()
  const [refusing, setRefusing] = useState<AttendanceRequest | null>(null)
  const [reason, setReason] = useState('')
  const url = (id: number) => `/${kind === 'overtime' ? 'overtime-requests' : 'attendance-corrections'}/${id}/decide`

  const decide = useMutation({
    mutationFn: ({ id, approve }: { id: number; approve: boolean }) => api.post(url(id), { approve, reason: approve ? null : reason }),
    onSuccess: () => {
      setRefusing(null)
      setReason('')
      refresh()
    },
  })

  if (!requests.length) return <p className="py-4 text-sm text-slate-500">{emptyText}</p>

  const status = {
    en_attente: { label: 'En attente', className: 'bg-amber-100 text-amber-800' },
    approuve: { label: 'Validée', className: 'bg-emerald-100 text-emerald-800' },
    refuse: { label: 'Refusée', className: 'bg-rose-100 text-rose-800' },
  }

  return (
    <>
      {decide.isError && !refusing && <Alert>{errorMessage(decide.error)}</Alert>}
      <ul className="divide-y divide-slate-100 text-sm">
        {requests.map((r) => (
          <li key={r.id} className="flex flex-wrap items-center justify-between gap-3 py-2.5">
            <div>
              {showEmployee && <span className="font-medium">{r.employee.full_name} — </span>}
              <span>{formatDate(r.date)}</span>
              {r.minutes !== undefined && <span className="font-medium"> · {formatMinutes(r.minutes)}</span>}
              {r.segments && (
                <span className="text-slate-600">
                  {' '}
                  · {r.segments.map((s) => `${s.in}–${s.out}`).join(', ')}
                </span>
              )}
              <div className="text-xs text-slate-500">« {r.reason} »</div>
              {r.refusal_reason && <div className="text-xs text-rose-700">Refus : {r.refusal_reason}</div>}
            </div>
            <div className="flex items-center gap-2">
              {r.can_decide ? (
                <>
                  <Button variant="secondary" className="px-2.5 py-1.5" loading={decide.isPending && decide.variables?.id === r.id} onClick={() => decide.mutate({ id: r.id, approve: true })}>
                    <Check className="h-4 w-4 text-emerald-600" /> Valider
                  </Button>
                  <Button variant="secondary" className="px-2.5 py-1.5" onClick={() => setRefusing(r)}>
                    <X className="h-4 w-4 text-rose-600" /> Refuser
                  </Button>
                </>
              ) : (
                <Badge className={status[r.status].className}>{status[r.status].label}</Badge>
              )}
            </div>
          </li>
        ))}
      </ul>
      {refusing && (
        <Modal open title="Motif du refus" onClose={() => setRefusing(null)}>
          <form
            className="space-y-4"
            onSubmit={(e) => {
              e.preventDefault()
              decide.mutate({ id: refusing.id, approve: false })
            }}
          >
            <Field label="Motif" required error={validationErrors(decide.error).reason?.[0]}>
              <Input autoFocus value={reason} onChange={(e) => setReason(e.target.value)} />
            </Field>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="ghost" onClick={() => setRefusing(null)}>
                Retour
              </Button>
              <Button type="submit" variant="danger" loading={decide.isPending}>
                Refuser
              </Button>
            </div>
          </form>
        </Modal>
      )}
    </>
  )
}
