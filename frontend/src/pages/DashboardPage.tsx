import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api } from '../api/client'
import type { ContractAlert, Dashboard } from '../api/types'
import { useAuth } from '../auth/useAuth'
import { Badge, Card, PageHeader, Spinner } from '../components/ui'
import { contractTypeLabels, formatDate } from '../lib/labels'
import { ExpiringDocuments } from './documents/DocumentsAdminPage'

function Stat({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
      <div className="text-sm text-slate-500">{label}</div>
      <div className="mt-2 text-3xl font-semibold text-slate-900">{value}</div>
    </div>
  )
}

/** CTR-04 : fins de contrat et de période d'essai dans les 30 jours. */
function ContractAlerts() {
  const { data, isLoading } = useQuery({
    queryKey: ['contract-alerts'],
    queryFn: async () => (await api.get<ContractAlert[]>('/contracts/expiring?days=30')).data,
  })

  return (
    <Card title="Échéances à venir (30 jours)">
      {isLoading ? (
        <Spinner />
      ) : !data?.length ? (
        <p className="text-sm text-slate-500">Aucune échéance dans les 30 prochains jours.</p>
      ) : (
        <ul className="divide-y divide-slate-100">
          {data.map((a) => (
            <li key={`${a.kind}-${a.contract_id}`} className="flex items-center justify-between gap-3 py-2 text-sm">
              <div>
                <Link to={`/employes/${a.employee.id}`} className="font-medium text-brand-700 hover:underline">
                  {a.employee.full_name}
                </Link>
                <div className="text-xs text-slate-500">
                  {a.kind === 'fin_contrat' ? `Fin de ${contractTypeLabels[a.type]}` : "Fin de période d'essai"} le {formatDate(a.date)}
                </div>
              </div>
              <Badge className={a.days_left <= 7 ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-800'}>
                {a.days_left === 0 ? "Aujourd'hui" : `J-${a.days_left}`}
              </Badge>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

export default function DashboardPage() {
  const { user, hasRole } = useAuth()
  const { data, isLoading } = useQuery({
    queryKey: ['dashboard'],
    queryFn: async () => (await api.get<Dashboard>('/dashboard')).data,
  })

  if (isLoading || !data) return <Spinner />

  const max = Math.max(1, ...data.by_department.map((d) => d.count))

  return (
    <>
      <PageHeader title={`Bonjour ${user?.name.split(' ')[0] ?? ''}`} subtitle="Vue d'ensemble de votre périmètre" />

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <Stat label="Effectif actif" value={data.headcount} />
        <Stat label="En période d'essai" value={data.on_probation} />
        <Stat label="Entrées ce mois-ci" value={data.hires_this_month} />
        <Stat label="Sorties ce mois-ci" value={data.exits_this_month} />
      </div>

      <div className="mt-6 grid gap-6 lg:grid-cols-2">
        {data.by_department.length > 0 && (
          <Card title="Effectif par département">
            <ul className="space-y-3">
              {data.by_department.map((d) => (
                <li key={d.name}>
                  <div className="mb-1 flex justify-between text-sm">
                    <span>{d.name}</span>
                    <span className="font-medium">{d.count}</span>
                  </div>
                  <div className="h-2 rounded-full bg-slate-100">
                    <div className="h-2 rounded-full bg-brand-500" style={{ width: `${(d.count / max) * 100}%` }} />
                  </div>
                </li>
              ))}
            </ul>
          </Card>
        )}

        {hasRole('super-admin', 'rh', 'manager', 'direction') && <ContractAlerts />}
        {hasRole('super-admin', 'rh') && <ExpiringDocuments />}

        <Card title="Dernières arrivées">
          {data.recent_hires.length === 0 ? (
            <p className="text-sm text-slate-500">Aucune arrivée récente.</p>
          ) : (
            <ul className="divide-y divide-slate-100">
              {data.recent_hires.map((e) => (
                <li key={e.id} className="flex justify-between py-2 text-sm">
                  <Link to={`/employes/${e.id}`} className="font-medium text-brand-700 hover:underline">
                    {e.full_name}
                    <span className="ml-2 font-normal text-slate-500">{e.position}</span>
                  </Link>
                  <span className="text-slate-500">{formatDate(e.hire_date)}</span>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
    </>
  )
}
