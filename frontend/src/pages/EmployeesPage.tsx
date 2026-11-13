import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { ChevronDown, ChevronLeft, ChevronRight, Download, Plus, Search, Upload } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { api } from '../api/client'
import { useDepartments, useSites } from '../api/queries'
import type { Employee, Paginated } from '../api/types'
import { useAuth } from '../auth/useAuth'
import { Badge, Button, Input, PageHeader, Select, Spinner } from '../components/ui'
import { contractTypeLabels, formatDate, statusColors, statusLabels } from '../lib/labels'

/** Export de la liste avec les filtres en cours (EMP-07 / RAP-06). */
function ExportMenu({ query }: { query: string }) {
  const [open, setOpen] = useState(false)
  const params = new URLSearchParams(query)
  params.delete('page')
  const href = (format: string) => `/api/v1/employees/export?${new URLSearchParams([...params, ['format', format]]).toString()}`

  return (
    <div className="relative">
      <Button variant="secondary" onClick={() => setOpen(!open)} onBlur={() => setTimeout(() => setOpen(false), 150)}>
        <Download className="h-4 w-4" /> Exporter <ChevronDown className="h-3.5 w-3.5" />
      </Button>
      {open && (
        <div className="absolute right-0 z-20 mt-1 w-44 rounded-md border border-slate-200 bg-white py-1 text-sm shadow-lg">
          {[
            ['xlsx', 'Excel (.xlsx)'],
            ['csv', 'CSV'],
            ['pdf', 'PDF'],
          ].map(([format, label]) => (
            <a key={format} href={href(format)} className="block px-3 py-2 hover:bg-slate-50">
              {label}
            </a>
          ))}
        </div>
      )}
    </div>
  )
}

export default function EmployeesPage() {
  const { isHr } = useAuth()
  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('search') ?? '')
  const { data: departments } = useDepartments()
  const { data: sites } = useSites()

  // Recherche différée pour ne pas appeler l'API à chaque frappe
  useEffect(() => {
    if (search === (params.get('search') ?? '')) return
    const t = setTimeout(() => setParam('search', search), 300)
    return () => clearTimeout(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search])

  function setParam(key: string, value: string) {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    if (key !== 'page') next.delete('page')
    if (next.toString() !== params.toString()) setParams(next, { replace: true })
  }

  const query = params.toString()
  const { data, isLoading, isFetching } = useQuery({
    queryKey: ['employees', query],
    queryFn: async () => (await api.get<Paginated<Employee>>(`/employees?${query}`)).data,
    placeholderData: keepPreviousData,
  })

  return (
    <>
      <PageHeader
        title="Employés"
        subtitle={data ? `${data.meta.total} employé(s)` : undefined}
        actions={
          <>
            <ExportMenu query={query} />
            {isHr && (
              <>
                <Link to="/employes/import">
                  <Button variant="secondary">
                    <Upload className="h-4 w-4" /> Importer
                  </Button>
                </Link>
                <Link to="/employes/nouveau">
                  <Button>
                    <Plus className="h-4 w-4" /> Nouvel employé
                  </Button>
                </Link>
              </>
            )}
          </>
        }
      />

      <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div className="relative">
          <Search className="pointer-events-none absolute top-2.5 left-3 h-4 w-4 text-slate-400" />
          <Input className="pl-9" placeholder="Nom, matricule, e-mail…" value={search} onChange={(e) => setSearch(e.target.value)} />
        </div>
        <Select value={params.get('department_id') ?? ''} onChange={(e) => setParam('department_id', e.target.value)}>
          <option value="">Tous les départements</option>
          {departments?.map((d) => (
            <option key={d.id} value={d.id}>
              {d.name}
            </option>
          ))}
        </Select>
        <Select value={params.get('site_id') ?? ''} onChange={(e) => setParam('site_id', e.target.value)}>
          <option value="">Tous les sites</option>
          {sites?.map((s) => (
            <option key={s.id} value={s.id}>
              {s.name}
            </option>
          ))}
        </Select>
        <Select value={params.get('status') ?? ''} onChange={(e) => setParam('status', e.target.value)}>
          <option value="">Tous les statuts</option>
          {Object.entries(statusLabels).map(([value, label]) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </Select>
      </div>

      <div className={`overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm ${isFetching ? 'opacity-70' : ''}`}>
        {isLoading ? (
          <Spinner />
        ) : (
          <table className="min-w-full divide-y divide-slate-200 text-sm">
            <thead className="bg-slate-50 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
              <tr>
                <th className="px-4 py-3">Matricule</th>
                <th className="px-4 py-3">Nom</th>
                <th className="px-4 py-3">Poste</th>
                <th className="px-4 py-3">Département</th>
                <th className="px-4 py-3">Site</th>
                <th className="px-4 py-3">Contrat</th>
                <th className="px-4 py-3">Embauche</th>
                <th className="px-4 py-3">Statut</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {data?.data.map((e) => (
                <tr key={e.id} className="hover:bg-slate-50">
                  <td className="px-4 py-3 font-mono text-xs text-slate-500">{e.matricule}</td>
                  <td className="px-4 py-3">
                    <Link to={`/employes/${e.id}`} className="font-medium text-brand-700 hover:underline">
                      {e.last_name.toUpperCase()} {e.first_name}
                    </Link>
                  </td>
                  <td className="px-4 py-3">{e.position?.title ?? '—'}</td>
                  <td className="px-4 py-3">{e.department?.name ?? '—'}</td>
                  <td className="px-4 py-3">{e.site?.name ?? '—'}</td>
                  <td className="px-4 py-3 whitespace-nowrap">
                    {e.contract ? (
                      <>
                        {contractTypeLabels[e.contract.type]}
                        {e.contract.end_date && <span className="text-slate-500"> → {formatDate(e.contract.end_date)}</span>}
                      </>
                    ) : (
                      '—'
                    )}
                  </td>
                  <td className="px-4 py-3">{formatDate(e.hire_date)}</td>
                  <td className="px-4 py-3">
                    <Badge className={statusColors[e.status]}>{statusLabels[e.status]}</Badge>
                  </td>
                </tr>
              ))}
              {data?.data.length === 0 && (
                <tr>
                  <td colSpan={8} className="px-4 py-10 text-center text-slate-500">
                    Aucun employé ne correspond à ces critères.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        )}
      </div>

      {data && data.meta.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-sm text-slate-600">
          <span>
            {data.meta.from}–{data.meta.to} sur {data.meta.total}
          </span>
          <div className="flex gap-2">
            <Button variant="secondary" disabled={data.meta.current_page <= 1} onClick={() => setParam('page', String(data.meta.current_page - 1))}>
              <ChevronLeft className="h-4 w-4" /> Précédent
            </Button>
            <Button
              variant="secondary"
              disabled={data.meta.current_page >= data.meta.last_page}
              onClick={() => setParam('page', String(data.meta.current_page + 1))}
            >
              Suivant <ChevronRight className="h-4 w-4" />
            </Button>
          </div>
        </div>
      )}
    </>
  )
}
