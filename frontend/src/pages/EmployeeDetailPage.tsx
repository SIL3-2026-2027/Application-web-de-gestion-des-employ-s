import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Archive, Pencil, Plus } from 'lucide-react'
import { useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api, errorMessage, validationErrors } from '../api/client'
import { useLeaveBalances } from '../api/queries'
import type { Employee, HistoryEntry, LeaveRequest, Paginated } from '../api/types'
import { useAuth } from '../auth/useAuth'
import { useInvalidateLeaves } from '../api/useInvalidateLeaves'
import { EmployeeContracts } from '../components/contracts'
import { EmployeeDocuments } from '../components/documents'
import { BalanceCards, LeaveRequestList, LeaveRequestModal } from '../components/leaves'
import { Alert, Badge, Button, Card, Field, Input, Modal, PageHeader, Select, Spinner } from '../components/ui'
import { THIS_YEAR, fieldLabels, formatDate, formatDateTime, formatDays, maritalLabels, statusColors, statusLabels } from '../lib/labels'

const TABS = ['Informations', 'Poste', 'Contrats', 'Congés', 'Documents', 'Historique'] as const
type Tab = (typeof TABS)[number]

function Info({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div>
      <dt className="text-xs font-medium tracking-wide text-slate-500 uppercase">{label}</dt>
      <dd className="mt-1 text-sm text-slate-900">{value || '—'}</dd>
    </div>
  )
}

export default function EmployeeDetailPage() {
  const { id } = useParams()
  const { isHr, user, hasRole } = useAuth()
  const [tab, setTab] = useState<Tab>('Informations')
  // La direction consulte les dossiers sans accès aux documents (sauf son propre dossier)
  const onlyDirection = hasRole('direction') && !isHr && String(user?.employee_id) !== id
  const [archiveOpen, setArchiveOpen] = useState(false)

  const { data: e, isLoading, error } = useQuery({
    queryKey: ['employee', id],
    queryFn: async () => (await api.get<{ data: Employee }>(`/employees/${id}`)).data.data,
  })

  if (isLoading) return <Spinner />
  if (error || !e) return <Alert>{errorMessage(error)}</Alert>

  const tabs = TABS.filter((t) => (t !== 'Historique' || isHr) && (t !== 'Documents' || !onlyDirection))

  return (
    <>
      <PageHeader
        title={`${e.civility ?? ''} ${e.first_name} ${e.last_name.toUpperCase()}`.trim()}
        subtitle={`${e.matricule} · ${e.position?.title ?? 'Poste non renseigné'}`}
        actions={
          isHr && (
            <>
              <Link to={`/employes/${e.id}/modifier`}>
                <Button variant="secondary">
                  <Pencil className="h-4 w-4" /> Modifier
                </Button>
              </Link>
              {e.status !== 'sorti' && (
                <Button variant="danger" onClick={() => setArchiveOpen(true)}>
                  <Archive className="h-4 w-4" /> Enregistrer un départ
                </Button>
              )}
            </>
          )
        }
      />

      <div className="mb-4 flex items-center gap-3">
        <Badge className={statusColors[e.status]}>{statusLabels[e.status]}</Badge>
        {e.status === 'sorti' && (
          <span className="text-sm text-slate-500">
            Sorti le {formatDate(e.exit_date)} — {e.exit_reason}
          </span>
        )}
      </div>

      <div className="mb-4 flex gap-1 border-b border-slate-200">
        {tabs.map((t) => (
          <button
            key={t}
            onClick={() => setTab(t)}
            className={`-mb-px border-b-2 px-4 py-2 text-sm ${t === tab ? 'border-brand-700 font-medium text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700'}`}
          >
            {t}
          </button>
        ))}
      </div>

      {tab === 'Informations' && (
        <div className="grid gap-6 lg:grid-cols-2">
          <Card title="Identité">
            <dl className="grid grid-cols-2 gap-4">
              <Info label="Nom" value={e.last_name} />
              <Info label="Prénom" value={e.first_name} />
              <Info label="Date de naissance" value={formatDate(e.birth_date)} />
              <Info label="Lieu de naissance" value={e.birth_place} />
              <Info label="Nationalité" value={e.nationality} />
              <Info label="Situation familiale" value={e.marital_status ? maritalLabels[e.marital_status] : null} />
              <Info label="Enfants" value={String(e.children_count)} />
              {'social_security_number' in e && <Info label="N° CNSS" value={e.social_security_number} />}
            </dl>
          </Card>
          <Card title="Coordonnées">
            <dl className="grid grid-cols-2 gap-4">
              <Info label="E-mail professionnel" value={e.work_email} />
              <Info label="E-mail personnel" value={e.personal_email} />
              <Info label="Téléphone" value={e.phone} />
              <Info label="Adresse" value={[e.address, [e.postal_code, e.city].filter(Boolean).join(' ')].filter(Boolean).join(', ')} />
              <Info label="Contact d'urgence" value={e.emergency_contact_name} />
              <Info label="Téléphone d'urgence" value={e.emergency_contact_phone} />
              {'iban' in e && <Info label="RIB / IBAN" value={e.iban} />}
            </dl>
          </Card>
        </div>
      )}

      {tab === 'Poste' && (
        <Card title="Poste et rattachement">
          <dl className="grid gap-4 sm:grid-cols-3">
            <Info label="Poste" value={e.position?.title} />
            <Info label="Département" value={e.department?.name} />
            <Info label="Site" value={e.site?.name} />
            <Info
              label="Manager"
              value={e.manager && <Link className="text-brand-700 hover:underline" to={`/employes/${e.manager.id}`}>{e.manager.full_name}</Link>}
            />
            <Info label="Date d'embauche" value={formatDate(e.hire_date)} />
            <Info
              label="Compte de connexion"
              value={
                e.has_account ? (
                  'Oui'
                ) : isHr && e.status !== 'sorti' ? (
                  <Link className="text-brand-700 hover:underline" to={`/administration/comptes?employe=${e.id}`}>
                    Non — créer le compte
                  </Link>
                ) : (
                  'Non'
                )
              }
            />
          </dl>
        </Card>
      )}

      {tab === 'Contrats' && <EmployeeContracts employee={e} />}
      {tab === 'Congés' && <EmployeeLeaves employee={e} />}
      {tab === 'Documents' && <EmployeeDocuments employee={e} />}
      {tab === 'Historique' && <History employeeId={e.id} />}

      <ArchiveModal employee={e} open={archiveOpen} onClose={() => setArchiveOpen(false)} />
    </>
  )
}

function History({ employeeId }: { employeeId: number }) {
  const { data, isLoading } = useQuery({
    queryKey: ['employee', String(employeeId), 'history'],
    queryFn: async () => (await api.get<HistoryEntry[]>(`/employees/${employeeId}/history`)).data,
  })

  if (isLoading) return <Spinner />

  return (
    <Card title="Historique des modifications">
      {!data?.length && <p className="text-sm text-slate-500">Aucune modification enregistrée.</p>}
      <ol className="space-y-4">
        {data?.map((h) => (
          <li key={h.id} className="border-l-2 border-brand-100 pl-4">
            <div className="text-sm">
              <span className="font-medium">{h.event === 'created' ? 'Création du dossier' : 'Modification'}</span>
              <span className="text-slate-500">
                {' '}
                par {h.causer ?? 'système'} le {formatDateTime(h.created_at)}
              </span>
            </div>
            {h.event === 'updated' && h.changes.attributes && (
              <ul className="mt-1 text-sm text-slate-600">
                {Object.entries(h.changes.attributes).map(([field, value]) => (
                  <li key={field}>
                    {fieldLabels[field] ?? field} : <span className="text-slate-400 line-through">{String(h.changes.old?.[field] ?? '—')}</span> →{' '}
                    {String(value ?? '—')}
                  </li>
                ))}
              </ul>
            )}
          </li>
        ))}
      </ol>
    </Card>
  )
}

function ArchiveModal({ employee, open, onClose }: { employee: Employee; open: boolean; onClose: () => void }) {
  const queryClient = useQueryClient()
  const [exitDate, setExitDate] = useState(() => new Date().toISOString().slice(0, 10))
  const [reason, setReason] = useState('')

  const archive = useMutation({
    mutationFn: async () =>
      (await api.post<{ data: Employee }>(`/employees/${employee.id}/archive`, { exit_date: exitDate, exit_reason: reason })).data.data,
    onSuccess: (updated) => {
      queryClient.setQueryData(['employee', String(employee.id)], updated)
      queryClient.invalidateQueries({ queryKey: ['employees'] })
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      onClose()
    },
  })
  const errors = validationErrors(archive.error)

  return (
    <Modal open={open} title={`Départ de ${employee.full_name}`} onClose={onClose}>
      <div className="space-y-4">
        <p className="text-sm text-slate-600">Le dossier est conservé et passe au statut « Sorti ». Le compte utilisateur est désactivé.</p>
        <Field label="Date de sortie" required error={errors.exit_date?.[0]}>
          <Input type="date" value={exitDate} onChange={(ev) => setExitDate(ev.target.value)} />
        </Field>
        <Field label="Motif" required error={errors.exit_reason?.[0]}>
          <Input list="exit-reasons" value={reason} onChange={(ev) => setReason(ev.target.value)} />
          <datalist id="exit-reasons">
            {['Démission', 'Licenciement', 'Fin de CDD', 'Rupture conventionnelle', 'Retraite', "Fin de période d'essai"].map((r) => (
              <option key={r} value={r} />
            ))}
          </datalist>
        </Field>
        {archive.isError && Object.keys(errors).length === 0 && <Alert>{errorMessage(archive.error)}</Alert>}
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Annuler
          </Button>
          <Button variant="danger" loading={archive.isPending} onClick={() => archive.mutate()}>
            Confirmer le départ
          </Button>
        </div>
      </div>
    </Modal>
  )
}

function EmployeeLeaves({ employee }: { employee: Employee }) {
  const { isHr } = useAuth()
  const [year, setYear] = useState(THIS_YEAR)
  const [declaring, setDeclaring] = useState(false)

  const balances = useLeaveBalances(employee.id, year)
  const requests = useQuery({
    queryKey: ['leave-requests', 'employee', employee.id, year],
    queryFn: async () => (await api.get<Paginated<LeaveRequest>>(`/leave-requests?scope=all&employee_id=${employee.id}&year=${year}`)).data,
  })

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap justify-between gap-2">
        <Select value={year} onChange={(ev) => setYear(Number(ev.target.value))} className="w-28">
          {[THIS_YEAR - 1, THIS_YEAR, THIS_YEAR + 1].map((y) => (
            <option key={y}>{y}</option>
          ))}
        </Select>
        {isHr && employee.status !== 'sorti' && (
          <Button onClick={() => setDeclaring(true)}>
            <Plus className="h-4 w-4" /> Déclarer une absence
          </Button>
        )}
      </div>

      {balances.isLoading ? <Spinner /> : <BalanceCards balances={balances.data?.balances ?? []} />}

      {isHr && balances.data && <Adjustments employeeId={employee.id} year={year} data={balances.data} />}

      <Card title={`Demandes ${year}`}>
        {requests.isLoading ? <Spinner /> : <LeaveRequestList requests={requests.data?.data ?? []} emptyText="Aucune demande pour cette année." />}
      </Card>

      {declaring && <LeaveRequestModal open employeeId={employee.id} onClose={() => setDeclaring(false)} />}
    </div>
  )
}

function Adjustments({ employeeId, year, data }: { employeeId: number; year: number; data: NonNullable<ReturnType<typeof useLeaveBalances>['data']> }) {
  const invalidate = useInvalidateLeaves()
  const [form, setForm] = useState({ leave_type_id: '', days: '', reason: '' })
  const save = useMutation({
    mutationFn: () => api.post(`/employees/${employeeId}/leave-adjustments`, { ...form, year }),
    onSuccess: () => {
      setForm({ leave_type_id: '', days: '', reason: '' })
      invalidate()
    },
  })
  const errors = validationErrors(save.error)

  return (
    <Card title="Ajustements de solde">
      <form
        className="grid gap-3 sm:grid-cols-[1fr_7rem_2fr_auto] sm:items-start"
        onSubmit={(ev) => {
          ev.preventDefault()
          save.mutate()
        }}
      >
        <Field label="Type" error={errors.leave_type_id?.[0]}>
          <Select value={form.leave_type_id} onChange={(ev) => setForm({ ...form, leave_type_id: ev.target.value })}>
            <option value="">Choisir…</option>
            {data.balances.map((b) => (
              <option key={b.leave_type_id} value={b.leave_type_id}>
                {b.leave_type}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Jours (±)" error={errors.days?.[0]}>
          <Input type="number" step="0.5" value={form.days} onChange={(ev) => setForm({ ...form, days: ev.target.value })} placeholder="ex. -1" />
        </Field>
        <Field label="Justification" error={errors.reason?.[0]}>
          <Input value={form.reason} onChange={(ev) => setForm({ ...form, reason: ev.target.value })} placeholder="ex. Report de l'année précédente" />
        </Field>
        <Button type="submit" variant="secondary" className="sm:mt-6" loading={save.isPending}>
          Ajouter
        </Button>
      </form>
      {data.adjustments.length > 0 && (
        <ul className="mt-4 divide-y divide-slate-100 text-sm">
          {data.adjustments.map((a) => (
            <li key={a.id} className="flex justify-between gap-4 py-2">
              <span>
                <span className={a.days > 0 ? 'text-emerald-700' : 'text-rose-700'}>
                  {a.days > 0 ? '+' : ''}
                  {formatDays(a.days)}
                </span>{' '}
                {a.leave_type} — {a.reason}
              </span>
              <span className="text-slate-500">
                {a.author} · {formatDateTime(a.created_at)}
              </span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}
