import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { useMemo, useState } from 'react'
import { api } from '../../api/client'
import { useDepartments } from '../../api/queries'
import type { CalendarData } from '../../api/types'
import { useAuth } from '../../auth/useAuth'
import { TypeDot } from '../../components/leaves'
import { Button, Card, PageHeader, Select, Spinner } from '../../components/ui'
import { leaveStatusLabels, toIsoDate } from '../../lib/labels'

/** Au-delà de cette part de l'effectif absent, la journée est signalée (CNG-05). */
const ALERT_RATIO = 0.5

const WEEKDAYS = ['D', 'L', 'M', 'M', 'J', 'V', 'S']

export default function LeaveCalendarPage() {
  const { canViewAll } = useAuthScope()
  const [month, setMonth] = useState(() => {
    const d = new Date()
    return new Date(d.getFullYear(), d.getMonth(), 1)
  })
  const [todayIso] = useState(() => toIsoDate(new Date()))
  const [departmentId, setDepartmentId] = useState('')
  const { data: departments } = useDepartments()

  const monthKey = `${month.getFullYear()}-${String(month.getMonth() + 1).padStart(2, '0')}`
  const { data, isLoading } = useQuery({
    queryKey: ['leave-calendar', monthKey, departmentId],
    queryFn: async () => (await api.get<CalendarData>(`/leave-calendar?month=${monthKey}${departmentId ? `&department_id=${departmentId}` : ''}`)).data,
    placeholderData: keepPreviousData,
  })

  const days = useMemo(() => {
    const count = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate()
    return Array.from({ length: count }, (_, i) => new Date(month.getFullYear(), month.getMonth(), i + 1))
  }, [month])

  // Index : employé → jour → absence
  const grid = useMemo(() => {
    const map = new Map<string, CalendarData['leaves'][number]>()
    for (const l of data?.leaves ?? []) {
      for (const d of days) {
        const iso = toIsoDate(d)
        if (iso >= l.start_date && iso <= l.end_date) map.set(`${l.employee_id}|${iso}`, l)
      }
    }
    return map
  }, [data, days])

  const holidays = new Map((data?.holidays ?? []).map((h) => [h.date, h.name]))
  const legend = [...new Map((data?.leaves ?? []).map((l) => [l.leave_type.code, l.leave_type])).values()]
  const shift = (n: number) => setMonth(new Date(month.getFullYear(), month.getMonth() + n, 1))

  return (
    <>
      <PageHeader
        title="Calendrier des absences"
        actions={
          <>
            {canViewAll && (
              <Select value={departmentId} onChange={(e) => setDepartmentId(e.target.value)} className="w-56">
                <option value="">Tous les départements</option>
                {departments?.map((d) => (
                  <option key={d.id} value={d.id}>
                    {d.name}
                  </option>
                ))}
              </Select>
            )}
            <Button variant="secondary" onClick={() => shift(-1)} aria-label="Mois précédent">
              <ChevronLeft className="h-4 w-4" />
            </Button>
            <div className="flex w-40 items-center justify-center font-medium capitalize">
              {month.toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })}
            </div>
            <Button variant="secondary" onClick={() => shift(1)} aria-label="Mois suivant">
              <ChevronRight className="h-4 w-4" />
            </Button>
          </>
        }
      />

      <Card>
        {isLoading || !data ? (
          <Spinner />
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="border-separate border-spacing-0 text-xs">
                <thead>
                  <tr>
                    <th className="sticky left-0 z-10 min-w-44 bg-white pr-3 text-left font-semibold text-slate-500">Employé</th>
                    {days.map((d) => {
                      const iso = toIsoDate(d)
                      const off = d.getDay() === 0 || d.getDay() === 6 || holidays.has(iso)
                      const absent = data.employees.filter((e) => grid.has(`${e.id}|${iso}`)).length
                      const alert = !off && data.employees.length > 1 && absent / data.employees.length > ALERT_RATIO
                      return (
                        <th
                          key={iso}
                          title={holidays.get(iso) ?? (alert ? `${absent} absent(s) sur ${data.employees.length}` : undefined)}
                          className={`w-7 min-w-7 pb-1 text-center font-normal ${off ? 'text-slate-300' : 'text-slate-500'} ${alert ? 'bg-rose-100 text-rose-700' : ''} ${iso === todayIso ? 'font-bold text-brand-700' : ''}`}
                        >
                          <div>{WEEKDAYS[d.getDay()]}</div>
                          <div>{d.getDate()}</div>
                        </th>
                      )
                    })}
                  </tr>
                </thead>
                <tbody>
                  {data.employees.map((e) => (
                    <tr key={e.id}>
                      <td className="sticky left-0 z-10 truncate border-t border-slate-100 bg-white py-1 pr-3 text-sm whitespace-nowrap">{e.full_name}</td>
                      {days.map((d) => {
                        const iso = toIsoDate(d)
                        const off = d.getDay() === 0 || d.getDay() === 6 || holidays.has(iso)
                        const leave = grid.get(`${e.id}|${iso}`)
                        const half = leave && ((iso === leave.start_date && leave.start_half_day) || (iso === leave.end_date && leave.end_half_day))
                        const pending = leave && leave.status !== 'approuve'
                        return (
                          <td key={iso} className={`h-8 border-t border-slate-100 p-0.5 ${off ? 'bg-slate-50' : ''}`}>
                            {leave && !off && (
                              <div
                                title={`${leave.leave_type.name} — ${leaveStatusLabels[leave.status]}`}
                                className={`h-full rounded-sm ${half ? 'w-1/2' : 'w-full'} ${pending ? 'opacity-50' : ''}`}
                                style={{
                                  backgroundColor: leave.leave_type.color,
                                  backgroundImage: pending ? 'repeating-linear-gradient(45deg, transparent 0 3px, rgba(255,255,255,.6) 3px 6px)' : undefined,
                                }}
                              />
                            )}
                          </td>
                        )
                      })}
                    </tr>
                  ))}
                  {data.employees.length === 0 && (
                    <tr>
                      <td colSpan={days.length + 1} className="py-6 text-center text-slate-500">
                        Aucun employé dans ce périmètre.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>

            <div className="mt-4 flex flex-wrap gap-x-5 gap-y-2 text-xs text-slate-600">
              {legend.map((t) => (
                <span key={t.code} className="flex items-center gap-1.5">
                  <TypeDot color={t.color} /> {t.name}
                </span>
              ))}
              <span>Hachuré : en attente de validation</span>
              <span className="flex items-center gap-1.5">
                <span className="inline-block h-2.5 w-4 rounded-sm bg-rose-100" /> Plus de {ALERT_RATIO * 100} % de l'effectif absent
              </span>
            </div>
          </>
        )}
      </Card>
    </>
  )
}

function useAuthScope() {
  const { hasRole } = useAuth()
  return { canViewAll: hasRole('super-admin', 'rh', 'direction') }
}
