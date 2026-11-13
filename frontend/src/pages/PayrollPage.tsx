import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Calculator, ChevronDown, Download, FileUp, Lock, LockOpen, Pencil, Plus, Trash2, Wand2 } from 'lucide-react'
import { useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, errorMessage, validationErrors } from '../api/client'
import { useEmployeeOptions } from '../api/queries'
import type { PayrollItem, PayrollPeriodDetail, PayrollRubric, RecurringPayItem } from '../api/types'
import { MonthPicker } from '../components/attendance'
import { Alert, Badge, Button, Card, Field, Input, Modal, PageHeader, Select, Spinner } from '../components/ui'
import { formatDate, formatDateTime, formatMoney, toIsoDate } from '../lib/labels'

type Tab = 'month' | 'recurring' | 'rubrics'

const sourceLabels: Record<PayrollItem['source'], string> = {
  manuel: 'Saisie',
  recurrent: 'Récurrent',
  heures_sup: 'Heures sup. validées',
  conge: 'Congés',
  absence: 'Feuille de temps',
}

const useRubrics = () => useQuery({ queryKey: ['payroll', 'rubrics'], queryFn: async () => (await api.get<PayrollRubric[]>('/payroll/rubrics')).data })

function formatItem(i: Pick<PayrollItem, 'quantity' | 'amount' | 'rubric'>): string {
  if (i.rubric.unit === 'montant') return money(i.amount ?? 0)
  const q = (i.quantity ?? 0).toLocaleString('fr-FR', { maximumFractionDigits: 2 })
  return i.rubric.unit === 'heures' ? `${q} h` : `${q} j`
}

function money(value: number): string {
  return formatMoney(value)
}

function ItemModal({ month, employeeId, item, onClose }: { month: string; employeeId?: number; item?: PayrollItem; onClose: () => void }) {
  const queryClient = useQueryClient()
  const { data: rubrics } = useRubrics()
  const { data: employees } = useEmployeeOptions()
  const [form, setForm] = useState({
    employee_id: String(item?.employee_id ?? employeeId ?? ''),
    payroll_rubric_id: String(item?.rubric.id ?? ''),
    quantity: item?.quantity?.toString() ?? '',
    amount: item?.amount?.toString() ?? '',
    comment: item?.comment ?? '',
  })
  const rubric = rubrics?.find((r) => String(r.id) === form.payroll_rubric_id)

  const save = useMutation({
    mutationFn: async () => {
      const payload = {
        payroll_rubric_id: form.payroll_rubric_id,
        quantity: rubric?.unit !== 'montant' ? form.quantity || null : null,
        amount: rubric?.unit === 'montant' ? form.amount || null : null,
        comment: form.comment || null,
      }
      if (item) await api.put(`/payroll/items/${item.id}`, payload)
      else await api.post(`/payroll/periods/${month}/items`, { ...payload, employee_id: form.employee_id })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payroll'] })
      onClose()
    },
  })
  const errors = validationErrors(save.error)

  return (
    <Modal open title={item ? 'Modifier l’élément' : 'Ajouter un élément de paie'} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {!item && (
          <Field label="Salarié" required error={errors.employee_id?.[0]}>
            <Select value={form.employee_id} onChange={(e) => setForm({ ...form, employee_id: e.target.value })}>
              <option value="">Choisir…</option>
              {employees?.map((e) => (
                <option key={e.id} value={e.id}>
                  {e.label}
                </option>
              ))}
            </Select>
          </Field>
        )}
        <Field label="Rubrique" required error={errors.payroll_rubric_id?.[0]}>
          <Select value={form.payroll_rubric_id} onChange={(e) => setForm({ ...form, payroll_rubric_id: e.target.value })}>
            <option value="">Choisir…</option>
            {rubrics
              ?.filter((r) => r.is_active)
              .map((r) => (
                <option key={r.id} value={r.id}>
                  {r.label} ({r.unit})
                </option>
              ))}
          </Select>
        </Field>
        {rubric && rubric.unit === 'montant' && (
          <Field label="Montant (FCFA)" required error={errors.amount?.[0]}>
            <Input type="number" step="1" min={0} value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
          </Field>
        )}
        {rubric && rubric.unit !== 'montant' && (
          <Field label={rubric.unit === 'heures' ? 'Nombre d’heures' : 'Nombre de jours'} required error={errors.quantity?.[0]}>
            <Input type="number" step="0.25" min={0} value={form.quantity} onChange={(e) => setForm({ ...form, quantity: e.target.value })} />
          </Field>
        )}
        <Field label="Commentaire" error={errors.comment?.[0]}>
          <Input value={form.comment} onChange={(e) => setForm({ ...form, comment: e.target.value })} />
        </Field>
        {item && item.source !== 'manuel' && (
          <p className="text-xs text-amber-700">Cet élément calculé automatiquement deviendra une saisie manuelle : il ne sera plus recalculé.</p>
        )}
        {save.isError && Object.keys(errors).length === 0 && <Alert>{errorMessage(save.error)}</Alert>}
        {errors.period && <Alert>{errors.period[0]}</Alert>}
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

function ExportMenu({ month }: { month: string }) {
  const [open, setOpen] = useState(false)
  const links = [
    ['csv', 'lignes', 'CSV — une ligne par élément'],
    ['xlsx', 'lignes', 'Excel — une ligne par élément'],
    ['xlsx', 'tableau', 'Excel — tableau par salarié'],
    ['csv', 'tableau', 'CSV — tableau par salarié'],
  ]
  return (
    <div className="relative">
      <Button variant="secondary" onClick={() => setOpen(!open)} onBlur={() => setTimeout(() => setOpen(false), 150)}>
        <Download className="h-4 w-4" /> Exporter <ChevronDown className="h-3.5 w-3.5" />
      </Button>
      {open && (
        <div className="absolute right-0 z-20 mt-1 w-64 rounded-md border border-slate-200 bg-white py-1 text-sm shadow-lg">
          {links.map(([format, layout, label]) => (
            <a key={label} href={`/api/v1/payroll/periods/${month}/export?format=${format}&layout=${layout}`} className="block px-3 py-2 hover:bg-slate-50">
              {label}
            </a>
          ))}
        </div>
      )}
    </div>
  )
}

function MonthView() {
  const queryClient = useQueryClient()
  const [month, setMonth] = useState(() => {
    const d = new Date()
    d.setDate(1)
    d.setMonth(d.getMonth() - 1) // par défaut : le mois qui vient de se terminer
    return toIsoDate(d).slice(0, 7)
  })
  const [itemModal, setItemModal] = useState<{ employeeId?: number; item?: PayrollItem } | null>(null)
  const [reopening, setReopening] = useState(false)
  const [reason, setReason] = useState('')
  const [slips, setSlips] = useState<{ matched: { file: string; employee: string }[]; unmatched: string[] } | null>(null)
  const fileInput = useRef<HTMLInputElement>(null)

  const { data, isLoading } = useQuery({
    queryKey: ['payroll', 'period', month],
    queryFn: async () => (await api.get<PayrollPeriodDetail>(`/payroll/periods/${month}`)).data,
  })
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['payroll'] })

  const prepare = useMutation({ mutationFn: () => api.post(`/payroll/periods/${month}/prepare`), onSuccess: refresh })
  const close = useMutation({ mutationFn: () => api.post(`/payroll/periods/${month}/close`), onSuccess: refresh })
  const reopen = useMutation({
    mutationFn: () => api.post(`/payroll/periods/${month}/reopen`, { reason }),
    onSuccess: () => {
      setReopening(false)
      setReason('')
      refresh()
    },
  })
  const remove = useMutation({ mutationFn: (id: number) => api.delete(`/payroll/items/${id}`), onSuccess: refresh })
  const upload = useMutation({
    mutationFn: async (files: FileList) => {
      const form = new FormData()
      Array.from(files).forEach((f) => form.append('files[]', f))
      return (await api.post<{ matched: { file: string; employee: string }[]; unmatched: string[] }>(`/payroll/periods/${month}/payslips`, form)).data
    },
    onSuccess: (r) => setSlips(r),
  })

  const error = prepare.error ?? close.error ?? remove.error ?? upload.error
  const open = data?.status === 'ouverte'
  const items = data?.employees.flatMap((e) => e.items) ?? []
  const sum = (filter: (i: PayrollItem) => boolean, field: 'amount' | 'quantity') => items.filter(filter).reduce((s, i) => s + (i[field] ?? 0), 0)

  return (
    <>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <MonthPicker value={month} onChange={setMonth} />
          {data && (
            <Badge className={open ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700'}>
              {open ? 'Ouverte' : `Clôturée le ${formatDate(data.closed_at!.slice(0, 10))}${data.closed_by ? ` par ${data.closed_by}` : ''}`}
            </Badge>
          )}
        </div>
        <div className="flex flex-wrap gap-2">
          {open ? (
            <>
              <Button variant="secondary" loading={prepare.isPending} onClick={() => prepare.mutate()}>
                <Calculator className="h-4 w-4" /> {data?.computed_at ? 'Recalculer' : 'Préparer la paie'}
              </Button>
              <Button variant="secondary" onClick={() => setItemModal({})}>
                <Plus className="h-4 w-4" /> Élément
              </Button>
              <Button loading={close.isPending} disabled={!data?.computed_at} onClick={() => confirm(`Clôturer la paie de ${month} ? Elle ne pourra plus être modifiée sans réouverture.`) && close.mutate()}>
                <Lock className="h-4 w-4" /> Clôturer
              </Button>
            </>
          ) : (
            <Button variant="secondary" onClick={() => setReopening(true)}>
              <LockOpen className="h-4 w-4" /> Rouvrir
            </Button>
          )}
          <ExportMenu month={month} />
          <input ref={fileInput} type="file" accept=".pdf" multiple hidden onChange={(e) => e.target.files?.length && upload.mutate(e.target.files)} />
          <Button variant="secondary" loading={upload.isPending} onClick={() => fileInput.current?.click()}>
            <FileUp className="h-4 w-4" /> Bulletins PDF
          </Button>
        </div>
      </div>

      {error && (
        <div className="mb-4">
          <Alert>{validationErrors(error).period?.[0] ?? validationErrors(error)['files.0']?.[0] ?? errorMessage(error)}</Alert>
        </div>
      )}

      {slips && (
        <div className="mb-4">
          <Alert tone={slips.unmatched.length ? 'error' : 'success'}>
            {slips.matched.length} bulletin(s) déposé(s) dans les dossiers et envoyé(s) aux salariés.
            {slips.unmatched.length > 0 && <> Non reconnus (matricule absent du nom du fichier) : {slips.unmatched.join(', ')}.</>}
          </Alert>
        </div>
      )}

      {isLoading || !data ? (
        <Spinner />
      ) : (
        <>
          <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
            {[
              ['Salariés', String(data.employees.length)],
              ['Primes et indemnités', money(sum((i) => i.rubric.kind === 'gain' && i.rubric.unit === 'montant', 'amount'))],
              ['Retenues', money(sum((i) => i.rubric.kind === 'retenue' && i.rubric.unit === 'montant', 'amount'))],
              ['Heures sup.', `${sum((i) => i.rubric.unit === 'heures', 'quantity').toLocaleString('fr-FR')} h`],
              ['Dernier calcul', data.computed_at ? formatDateTime(data.computed_at) : 'Jamais'],
            ].map(([label, value]) => (
              <div key={label} className="rounded-md border border-slate-200 bg-white px-3 py-2">
                <div className="text-xs text-slate-500">{label}</div>
                <div className="font-semibold">{value}</div>
              </div>
            ))}
          </div>

          {!data.computed_at && open && (
            <div className="mb-4">
              <Alert tone="success">
                Cliquez sur « Préparer la paie » : les heures supplémentaires validées, les congés, les absences et les éléments récurrents du mois seront repris automatiquement. Vous pourrez
                ensuite ajouter les primes et acomptes.
              </Alert>
            </div>
          )}

          <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
            <table className="min-w-full divide-y divide-slate-200 text-sm">
              <thead className="bg-slate-50 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
                <tr>
                  <th className="px-4 py-3">Salarié</th>
                  <th className="px-4 py-3">Salaire de base</th>
                  <th className="px-4 py-3">Éléments variables</th>
                  <th />
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {data.employees.map((e) => (
                  <tr key={e.id} className="align-top">
                    <td className="px-4 py-3">
                      <Link to={`/employes/${e.id}`} className="font-medium text-brand-700 hover:underline">
                        {e.full_name}
                      </Link>
                      <div className="text-xs text-slate-500">
                        {e.matricule} · {e.department ?? '—'} · {e.contract_type ?? 'sans contrat'}
                      </div>
                      {e.entry && <Badge className="mt-1 bg-sky-100 text-sky-800">Entrée le {formatDate(e.entry)}</Badge>}
                      {e.exit && <Badge className="mt-1 bg-rose-100 text-rose-800">Sortie le {formatDate(e.exit)}</Badge>}
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap">{e.monthly_salary !== null ? money(e.monthly_salary) : '—'}</td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap gap-1.5">
                        {e.items.map((i) => (
                          <span
                            key={i.id}
                            title={`${sourceLabels[i.source]}${i.comment ? ` — ${i.comment}` : ''}`}
                            className={`group inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs ${
                              i.rubric.kind === 'retenue' ? 'border-rose-200 bg-rose-50' : i.rubric.kind === 'information' ? 'border-slate-200 bg-slate-50' : 'border-emerald-200 bg-emerald-50'
                            }`}
                          >
                            {i.source !== 'manuel' && <Wand2 className="h-3 w-3 text-slate-400" />}
                            {i.rubric.label} : <strong>{formatItem(i)}</strong>
                            {open && (
                              <>
                                <button className="text-slate-400 hover:text-brand-700" onClick={() => setItemModal({ item: i })} aria-label="Modifier">
                                  <Pencil className="h-3 w-3" />
                                </button>
                                <button className="text-slate-400 hover:text-rose-600" onClick={() => remove.mutate(i.id)} aria-label="Supprimer">
                                  <Trash2 className="h-3 w-3" />
                                </button>
                              </>
                            )}
                          </span>
                        ))}
                        {e.items.length === 0 && <span className="text-xs text-slate-400">Aucun élément</span>}
                      </div>
                    </td>
                    <td className="px-4 py-3 text-right">
                      {open && (
                        <button className="text-slate-400 hover:text-brand-700" onClick={() => setItemModal({ employeeId: e.id })} aria-label="Ajouter un élément">
                          <Plus className="h-4 w-4" />
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="mt-2 flex items-center gap-1 text-xs text-slate-500">
            <Wand2 className="h-3 w-3" /> Élément calculé automatiquement. Une saisie manuelle sur la même rubrique remplace le calcul.
          </p>
        </>
      )}

      {itemModal && <ItemModal month={month} employeeId={itemModal.employeeId} item={itemModal.item} onClose={() => setItemModal(null)} />}
      {reopening && (
        <Modal open title={`Rouvrir la paie de ${month}`} onClose={() => setReopening(false)}>
          <form
            className="space-y-4"
            onSubmit={(e) => {
              e.preventDefault()
              reopen.mutate()
            }}
          >
            <p className="text-sm text-slate-600">La réouverture est enregistrée dans le journal avec son motif.</p>
            <Field label="Motif" required error={validationErrors(reopen.error).reason?.[0]}>
              <Input autoFocus value={reason} onChange={(e) => setReason(e.target.value)} placeholder="ex. prime oubliée" />
            </Field>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="ghost" onClick={() => setReopening(false)}>
                Annuler
              </Button>
              <Button type="submit" loading={reopen.isPending}>
                Rouvrir
              </Button>
            </div>
          </form>
        </Modal>
      )}
    </>
  )
}

function Recurring() {
  const queryClient = useQueryClient()
  const { data: rubrics } = useRubrics()
  const { data: employees } = useEmployeeOptions()
  const [editing, setEditing] = useState<Record<string, string> | null>(null)
  const { data, isLoading } = useQuery({
    queryKey: ['payroll', 'recurring'],
    queryFn: async () => (await api.get<RecurringPayItem[]>('/payroll/recurring')).data,
  })
  const save = useMutation({
    mutationFn: () => {
      const payload = { ...editing, end_month: editing?.end_month || null, comment: editing?.comment || null }
      return editing?.id ? api.put(`/payroll/recurring/${editing.id}`, payload) : api.post('/payroll/recurring', payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payroll'] })
      setEditing(null)
    },
  })
  const remove = useMutation({ mutationFn: (id: number) => api.delete(`/payroll/recurring/${id}`), onSuccess: () => queryClient.invalidateQueries({ queryKey: ['payroll'] }) })
  const errors = validationErrors(save.error)
  const [currentMonth] = useState(() => toIsoDate(new Date()).slice(0, 7))

  return (
    <Card
      title="Éléments récurrents"
      actions={
        <Button variant="secondary" onClick={() => setEditing({ employee_id: '', payroll_rubric_id: '', amount: '', start_month: currentMonth, end_month: '', comment: '' })}>
          <Plus className="h-4 w-4" /> Ajouter
        </Button>
      }
    >
      <p className="mb-3 text-sm text-slate-500">Primes fixes, indemnités ou avantages versés chaque mois : ils sont repris à chaque préparation de la paie.</p>
      {isLoading ? (
        <Spinner />
      ) : !data?.length ? (
        <p className="text-sm text-slate-500">Aucun élément récurrent.</p>
      ) : (
        <ul className="divide-y divide-slate-100 text-sm">
          {data.map((r) => (
            <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
              <span>
                <span className="font-medium">{r.employee.full_name}</span> — {r.rubric.label} : <strong>{money(r.amount)}</strong> / mois
                <span className="text-xs text-slate-500">
                  {' '}
                  depuis {r.start_month}
                  {r.end_month ? ` jusqu'à ${r.end_month}` : ''}
                  {r.comment && ` · ${r.comment}`}
                </span>
              </span>
              <span>
                <button
                  className="p-1 text-slate-400 hover:text-brand-700"
                  aria-label="Modifier"
                  onClick={() =>
                    setEditing({ id: String(r.id), employee_id: String(r.employee.id), payroll_rubric_id: String(r.rubric.id), amount: String(r.amount), start_month: r.start_month, end_month: r.end_month ?? '', comment: r.comment ?? '' })
                  }
                >
                  <Pencil className="h-4 w-4" />
                </button>
                <button className="p-1 text-slate-400 hover:text-rose-600" aria-label="Supprimer" onClick={() => confirm('Supprimer cet élément récurrent ?') && remove.mutate(r.id)}>
                  <Trash2 className="h-4 w-4" />
                </button>
              </span>
            </li>
          ))}
        </ul>
      )}
      {editing && (
        <Modal open title={editing.id ? 'Modifier l’élément récurrent' : 'Nouvel élément récurrent'} onClose={() => setEditing(null)}>
          <form
            className="space-y-4"
            onSubmit={(e) => {
              e.preventDefault()
              save.mutate()
            }}
          >
            <Field label="Salarié" required error={errors.employee_id?.[0]}>
              <Select value={editing.employee_id} onChange={(e) => setEditing({ ...editing, employee_id: e.target.value })}>
                <option value="">Choisir…</option>
                {employees?.map((e) => (
                  <option key={e.id} value={e.id}>
                    {e.label}
                  </option>
                ))}
              </Select>
            </Field>
            <Field label="Rubrique" required error={errors.payroll_rubric_id?.[0]}>
              <Select value={editing.payroll_rubric_id} onChange={(e) => setEditing({ ...editing, payroll_rubric_id: e.target.value })}>
                <option value="">Choisir…</option>
                {rubrics
                  ?.filter((r) => r.is_active && r.unit === 'montant')
                  .map((r) => (
                    <option key={r.id} value={r.id}>
                      {r.label}
                    </option>
                  ))}
              </Select>
            </Field>
            <Field label="Montant mensuel (FCFA)" required error={errors.amount?.[0]}>
              <Input type="number" step="1" min={0} value={editing.amount} onChange={(e) => setEditing({ ...editing, amount: e.target.value })} />
            </Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="À partir de" required error={errors.start_month?.[0]}>
                <Input type="month" value={editing.start_month} onChange={(e) => setEditing({ ...editing, start_month: e.target.value })} />
              </Field>
              <Field label="Jusqu'à (facultatif)" error={errors.end_month?.[0]}>
                <Input type="month" value={editing.end_month} onChange={(e) => setEditing({ ...editing, end_month: e.target.value })} />
              </Field>
            </div>
            <Field label="Commentaire" error={errors.comment?.[0]}>
              <Input value={editing.comment} onChange={(e) => setEditing({ ...editing, comment: e.target.value })} />
            </Field>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="ghost" onClick={() => setEditing(null)}>
                Annuler
              </Button>
              <Button type="submit" loading={save.isPending}>
                Enregistrer
              </Button>
            </div>
          </form>
        </Modal>
      )}
    </Card>
  )
}

const kindLabels = { gain: 'Gain', retenue: 'Retenue', information: 'Information' }

function Rubrics() {
  const queryClient = useQueryClient()
  const { data, isLoading } = useRubrics()
  const [editing, setEditing] = useState<Partial<PayrollRubric> | null>(null)
  const save = useMutation({
    mutationFn: async () => {
      if (editing?.id) await api.put(`/payroll/rubrics/${editing.id}`, editing)
      else await api.post('/payroll/rubrics', editing)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['payroll'] })
      setEditing(null)
    },
  })
  const remove = useMutation({ mutationFn: (id: number) => api.delete(`/payroll/rubrics/${id}`), onSuccess: () => queryClient.invalidateQueries({ queryKey: ['payroll'] }) })
  const errors = validationErrors(save.error)

  return (
    <Card
      title="Rubriques de paie"
      actions={
        <Button variant="secondary" onClick={() => setEditing({ code: '', label: '', kind: 'gain', unit: 'montant', export_code: '', is_active: true })}>
          <Plus className="h-4 w-4" /> Ajouter
        </Button>
      }
    >
      <p className="mb-3 text-sm text-slate-500">
        Le <strong>code d'export</strong> est celui de la rubrique dans votre logiciel de paie : il figure dans le fichier exporté. Les rubriques HS25, HS50 et ABS_NJ, ainsi que celles qui portent le
        code d'un type de congé (CP, PERM, MAL…), sont alimentées automatiquement.
      </p>
      {remove.isError && <Alert>{errorMessage(remove.error)}</Alert>}
      {isLoading ? (
        <Spinner />
      ) : (
        <table className="min-w-full text-sm">
          <thead className="text-left text-xs text-slate-500 uppercase">
            <tr>
              <th className="pb-2">Code</th>
              <th className="pb-2">Libellé</th>
              <th className="pb-2">Type</th>
              <th className="pb-2">Unité</th>
              <th className="pb-2">Code d'export</th>
              <th />
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {data?.map((r) => (
              <tr key={r.id} className={r.is_active ? '' : 'opacity-50'}>
                <td className="py-2 font-mono text-xs">{r.code}</td>
                <td className="py-2">{r.label}</td>
                <td className="py-2">{kindLabels[r.kind]}</td>
                <td className="py-2">{r.unit}</td>
                <td className="py-2 font-mono text-xs">{r.export_code ?? '—'}</td>
                <td className="py-2 text-right whitespace-nowrap">
                  <button className="p-1 text-slate-400 hover:text-brand-700" aria-label="Modifier" onClick={() => setEditing(r)}>
                    <Pencil className="h-4 w-4" />
                  </button>
                  <button className="p-1 text-slate-400 hover:text-rose-600" aria-label="Supprimer" onClick={() => confirm(`Supprimer « ${r.label} » ?`) && remove.mutate(r.id)}>
                    <Trash2 className="h-4 w-4" />
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {editing && (
        <Modal open title={editing.id ? 'Modifier la rubrique' : 'Nouvelle rubrique'} onClose={() => setEditing(null)}>
          <form
            className="space-y-4"
            onSubmit={(e) => {
              e.preventDefault()
              save.mutate()
            }}
          >
            <div className="grid grid-cols-2 gap-3">
              <Field label="Code" required error={errors.code?.[0]}>
                <Input value={editing.code ?? ''} onChange={(e) => setEditing({ ...editing, code: e.target.value.toUpperCase().replace(/\s/g, '_') })} />
              </Field>
              <Field label="Code d'export" error={errors.export_code?.[0]}>
                <Input value={editing.export_code ?? ''} onChange={(e) => setEditing({ ...editing, export_code: e.target.value })} />
              </Field>
            </div>
            <Field label="Libellé" required error={errors.label?.[0]}>
              <Input value={editing.label ?? ''} onChange={(e) => setEditing({ ...editing, label: e.target.value })} />
            </Field>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Type" required>
                <Select value={editing.kind} onChange={(e) => setEditing({ ...editing, kind: e.target.value as PayrollRubric['kind'] })}>
                  <option value="gain">Gain</option>
                  <option value="retenue">Retenue</option>
                  <option value="information">Information</option>
                </Select>
              </Field>
              <Field label="Unité" required>
                <Select value={editing.unit} onChange={(e) => setEditing({ ...editing, unit: e.target.value as PayrollRubric['unit'] })}>
                  <option value="montant">Montant (FCFA)</option>
                  <option value="heures">Heures</option>
                  <option value="jours">Jours</option>
                </Select>
              </Field>
            </div>
            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={!!editing.is_active} onChange={(e) => setEditing({ ...editing, is_active: e.target.checked })} /> Active
            </label>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="ghost" onClick={() => setEditing(null)}>
                Annuler
              </Button>
              <Button type="submit" loading={save.isPending}>
                Enregistrer
              </Button>
            </div>
          </form>
        </Modal>
      )}
    </Card>
  )
}

/** Paie (PAI-01 à PAI-05) : éléments variables du mois, récurrents, rubriques. */
export default function PayrollPage() {
  const [tab, setTab] = useState<Tab>('month')
  const tabs: [Tab, string][] = [
    ['month', 'Éléments du mois'],
    ['recurring', 'Éléments récurrents'],
    ['rubrics', 'Rubriques'],
  ]

  return (
    <>
      <PageHeader title="Paie" subtitle="Éléments variables à transmettre au logiciel de paie" />
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
      {tab === 'month' && <MonthView />}
      {tab === 'recurring' && <Recurring />}
      {tab === 'rubrics' && <Rubrics />}
    </>
  )
}
