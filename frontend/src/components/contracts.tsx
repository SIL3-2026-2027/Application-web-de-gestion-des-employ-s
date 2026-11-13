import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FileDown, FilePlus2, FileSignature, FileUp, Pencil, Plus, Trash2, XCircle } from 'lucide-react'
import { useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { api, errorMessage, validationErrors } from '../api/client'
import { useDepartments, usePositions } from '../api/queries'
import type { Contract, ContractType, Employee } from '../api/types'
import { useAuth } from '../auth/useAuth'
import {
  contractStatusColors,
  contractStatusLabels,
  contractTypeLabels,
  endReasonLabels,
  formatDate,
  LEGAL_WEEKLY_HOURS,
  formatMoney,
} from '../lib/labels'
import { Alert, Badge, Button, Card, Field, Input, Modal, Select, Spinner } from './ui'

const FIXED_TERM: ContractType[] = ['CDD', 'stage', 'alternance', 'interim', 'freelance']

function useContracts(employeeId: number) {
  return useQuery({
    queryKey: ['contracts', employeeId],
    queryFn: async () => (await api.get<{ data: Contract[] }>(`/employees/${employeeId}/contracts`)).data.data,
  })
}

function useRefreshContracts(employeeId: number) {
  const queryClient = useQueryClient()
  return () => {
    queryClient.invalidateQueries({ queryKey: ['contracts', employeeId] })
    queryClient.invalidateQueries({ queryKey: ['employee', String(employeeId)] })
    queryClient.invalidateQueries({ queryKey: ['employees'] })
    queryClient.invalidateQueries({ queryKey: ['contract-alerts'] })
  }
}

function DocLink({ href, children }: { href: string; children: ReactNode }) {
  return (
    <a href={href} className="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs text-brand-700 hover:bg-brand-50">
      {children}
    </a>
  )
}

/** Onglet « Contrats » de la fiche employé : contrats, avenants, documents (CTR-01 à 06). */
export function EmployeeContracts({ employee }: { employee: Employee }) {
  const { isHr } = useAuth()
  const { data, isLoading } = useContracts(employee.id)
  const [modal, setModal] = useState<
    { kind: 'create' } | { kind: 'edit'; contract: Contract } | { kind: 'amend'; contract: Contract } | { kind: 'end'; contract: Contract } | null
  >(null)

  if (isLoading) return <Spinner />

  const canManage = isHr
  const hasOpenContract = data?.some((c) => c.status !== 'termine') ?? false

  return (
    <div className="space-y-4">
      {isHr && !hasOpenContract && employee.status !== 'sorti' && (
        <div className="flex justify-end">
          <Button onClick={() => setModal({ kind: 'create' })}>
            <Plus className="h-4 w-4" /> Nouveau contrat
          </Button>
        </div>
      )}

      {data?.length === 0 && (
        <Card>
          <p className="text-sm text-slate-500">Aucun contrat enregistré.</p>
        </Card>
      )}

      {data?.map((c) => (
        <ContractCard
          key={c.id}
          contract={c}
          canManage={canManage}
          onEdit={() => setModal({ kind: 'edit', contract: c })}
          onAmend={() => setModal({ kind: 'amend', contract: c })}
          onEnd={() => setModal({ kind: 'end', contract: c })}
        />
      ))}

      {modal?.kind === 'create' && <ContractFormModal employee={employee} onClose={() => setModal(null)} />}
      {modal?.kind === 'edit' && <ContractFormModal employee={employee} contract={modal.contract} onClose={() => setModal(null)} />}
      {modal?.kind === 'amend' && <AmendmentModal contract={modal.contract} onClose={() => setModal(null)} />}
      {modal?.kind === 'end' && <EndContractModal contract={modal.contract} employee={employee} onClose={() => setModal(null)} />}
    </div>
  )
}

function Term({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div>
      <dt className="text-xs text-slate-500">{label}</dt>
      <dd className="text-sm font-medium text-slate-900">{value ?? '—'}</dd>
    </div>
  )
}

function ContractCard({ contract: c, canManage, onEdit, onAmend, onEnd }: { contract: Contract; canManage: boolean; onEdit: () => void; onAmend: () => void; onEnd: () => void }) {
  const refresh = useRefreshContracts(c.employee_id)
  const fileInput = useRef<HTMLInputElement>(null)
  const [uploadTarget, setUploadTarget] = useState<number>(c.id)

  const remove = useMutation({ mutationFn: () => api.delete(`/contracts/${c.id}`), onSuccess: refresh })
  const upload = useMutation({
    mutationFn: (file: File) => {
      const form = new FormData()
      form.append('document', file)
      return api.post(`/contracts/${uploadTarget}/document`, form)
    },
    onSuccess: refresh,
  })

  const pickFile = (id: number) => {
    setUploadTarget(id)
    fileInput.current?.click()
  }

  const open = c.status !== 'termine' && !c.ended_at
  const salary = c.current_terms.base_salary

  return (
    <Card
      title={`${contractTypeLabels[c.type]} — ${c.reference}`}
      actions={<Badge className={contractStatusColors[c.status]}>{contractStatusLabels[c.status]}</Badge>}
    >
      <input
        ref={fileInput}
        type="file"
        accept=".pdf,.jpg,.jpeg,.png"
        hidden
        onChange={(e) => {
          const f = e.target.files?.[0]
          if (f) upload.mutate(f)
          e.target.value = ''
        }}
      />

      <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <Term label="Début" value={formatDate(c.start_date)} />
        <Term
          label="Fin"
          value={
            c.ended_at
              ? `${formatDate(c.ended_at)} (${c.end_reason_label})`
              : c.effective_end_date
                ? formatDate(c.effective_end_date) + (c.effective_end_date !== c.end_date ? ' (prolongé)' : '')
                : 'Indéterminée'
          }
        />
        <Term label="Fin de période d'essai" value={c.trial_end_date ? formatDate(c.trial_end_date) : '—'} />
        <Term label="Convention" value={c.collective_agreement} />
        <Term label="Poste actuel" value={c.current_terms.position} />
        <Term label="Département" value={c.current_terms.department} />
        <Term label="Durée hebdo." value={c.current_terms.weekly_hours ? `${c.current_terms.weekly_hours} h` : '—'} />
        {salary !== undefined && <Term label="Salaire brut mensuel" value={formatMoney(salary)} />}
      </dl>
      {c.description && <p className="mt-3 text-sm text-slate-600">{c.description}</p>}
      {c.end_notes && <p className="mt-2 text-sm text-slate-500">Note de fin : {c.end_notes}</p>}

      {/* CTR-05 : historique des avenants */}
      {c.amendments.length > 0 && (
        <div className="mt-5">
          <h3 className="mb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase">Avenants</h3>
          <ol className="space-y-3 border-l-2 border-brand-100 pl-4">
            {c.amendments.map((a) => (
              <li key={a.id} className="text-sm">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-medium">{formatDate(a.start_date)}</span>
                  <span className="font-mono text-xs text-slate-400">{a.reference}</span>
                  {!a.applied && <Badge className="bg-sky-100 text-sky-800">À venir</Badge>}
                  {c.can.documents && <DocLink href={`/api/v1/contracts/${a.id}/pdf`}><FileDown className="h-3.5 w-3.5" /> PDF</DocLink>}
                  {c.can.documents && a.has_document && (
                    <DocLink href={`/api/v1/contracts/${a.id}/document`}><FileSignature className="h-3.5 w-3.5" /> Signé</DocLink>
                  )}
                  {canManage && (
                    <button className="text-xs text-slate-500 hover:text-brand-700" onClick={() => pickFile(a.id)}>
                      {a.has_document ? 'Remplacer le signé' : 'Déposer le signé'}
                    </button>
                  )}
                </div>
                <ul className="mt-1 text-slate-600">
                  {a.changes.position && <li>Poste : {a.changes.position}</li>}
                  {a.changes.department && <li>Département : {a.changes.department}</li>}
                  {a.changes.base_salary !== undefined && <li>Salaire : {formatMoney(a.changes.base_salary)}</li>}
                  {a.changes.weekly_hours !== undefined && <li>Durée hebdomadaire : {a.changes.weekly_hours} h</li>}
                  {a.changes.end_date && <li>Nouvelle date de fin : {formatDate(a.changes.end_date)}</li>}
                </ul>
                {a.description && <p className="text-slate-500">{a.description}</p>}
              </li>
            ))}
          </ol>
        </div>
      )}

      {(upload.isError || remove.isError) && (
        <div className="mt-4">
          <Alert>{errorMessage(upload.error ?? remove.error)}</Alert>
        </div>
      )}

      <div className="mt-5 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4">
        {c.can.documents && (
          <>
            <DocLink href={`/api/v1/contracts/${c.id}/pdf`}><FileDown className="h-4 w-4" /> Contrat PDF</DocLink>
            {c.has_document && <DocLink href={`/api/v1/contracts/${c.id}/document`}><FileSignature className="h-4 w-4" /> Exemplaire signé</DocLink>}
            {c.status === 'termine' && <DocLink href={`/api/v1/contracts/${c.id}/certificate`}><FileDown className="h-4 w-4" /> Certificat de travail</DocLink>}
          </>
        )}
        {canManage && (
          <div className="ml-auto flex flex-wrap gap-2">
            <Button variant="ghost" className="px-2.5 py-1.5" loading={upload.isPending && uploadTarget === c.id} onClick={() => pickFile(c.id)}>
              <FileUp className="h-4 w-4" /> {c.has_document ? 'Remplacer le signé' : 'Déposer le signé'}
            </Button>
            {open && (
              <>
                <Button variant="secondary" className="px-2.5 py-1.5" onClick={onAmend}>
                  <FilePlus2 className="h-4 w-4" /> Avenant
                </Button>
                <Button variant="secondary" className="px-2.5 py-1.5" onClick={onEdit}>
                  <Pencil className="h-4 w-4" /> Corriger
                </Button>
                <Button variant="secondary" className="px-2.5 py-1.5" onClick={onEnd}>
                  <XCircle className="h-4 w-4 text-rose-600" /> Terminer
                </Button>
                <Button
                  variant="ghost"
                  className="px-2.5 py-1.5 text-rose-600"
                  aria-label="Supprimer"
                  onClick={() => confirm(`Supprimer le contrat ${c.reference} et ses avenants ? (à réserver aux erreurs de saisie)`) && remove.mutate()}
                >
                  <Trash2 className="h-4 w-4" />
                </Button>
              </>
            )}
          </div>
        )}
      </div>
    </Card>
  )
}

function ContractFormModal({ employee, contract, onClose }: { employee: Employee; contract?: Contract; onClose: () => void }) {
  const refresh = useRefreshContracts(employee.id)
  const { data: positions } = usePositions()
  const { data: departments } = useDepartments()
  const [form, setForm] = useState({
    type: contract?.type ?? ('CDI' as ContractType),
    start_date: contract?.start_date ?? employee.hire_date,
    end_date: contract?.end_date ?? '',
    trial_end_date: contract?.trial_end_date ?? '',
    weekly_hours: String(contract?.weekly_hours ?? LEGAL_WEEKLY_HOURS),
    base_salary: contract?.base_salary !== undefined ? String(contract.base_salary) : '',
    position_id: String(contract?.position?.id ?? employee.position_id ?? ''),
    department_id: String(contract?.department?.id ?? employee.department_id ?? ''),
    collective_agreement: contract?.collective_agreement ?? '',
    description: contract?.description ?? '',
  })
  const set = (patch: Partial<typeof form>) => setForm({ ...form, ...patch })
  const fixedTerm = FIXED_TERM.includes(form.type)

  const save = useMutation({
    mutationFn: () => {
      const payload = Object.fromEntries(Object.entries({ ...form, end_date: fixedTerm ? form.end_date : '' }).map(([k, v]) => [k, v === '' ? null : v]))
      return contract ? api.put(`/contracts/${contract.id}`, payload) : api.post(`/employees/${employee.id}/contracts`, payload)
    },
    onSuccess: () => {
      refresh()
      onClose()
    },
  })
  const errors = validationErrors(save.error)

  return (
    <Modal open title={contract ? `Corriger le contrat ${contract.reference}` : `Nouveau contrat — ${employee.full_name}`} onClose={onClose}>
      <form
        className="grid gap-4 sm:grid-cols-2"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        <Field label="Type de contrat" required error={errors.type?.[0]}>
          <Select value={form.type} onChange={(e) => set({ type: e.target.value as ContractType })}>
            {Object.entries(contractTypeLabels).map(([v, l]) => (
              <option key={v} value={v}>
                {l}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Convention collective" error={errors.collective_agreement?.[0]}>
          <Input value={form.collective_agreement} onChange={(e) => set({ collective_agreement: e.target.value })} placeholder="ex. Convention collective générale du travail" />
        </Field>
        <Field label="Date de début" required error={errors.start_date?.[0]}>
          <Input type="date" value={form.start_date} onChange={(e) => set({ start_date: e.target.value })} />
        </Field>
        <Field label="Date de fin" required={fixedTerm} error={errors.end_date?.[0]}>
          <Input type="date" value={fixedTerm ? form.end_date : ''} disabled={!fixedTerm} onChange={(e) => set({ end_date: e.target.value })} />
        </Field>
        <Field label="Fin de période d'essai" error={errors.trial_end_date?.[0]}>
          <Input type="date" value={form.trial_end_date} onChange={(e) => set({ trial_end_date: e.target.value })} />
        </Field>
        <Field label="Durée hebdomadaire (h)" required error={errors.weekly_hours?.[0]}>
          <Input type="number" step="0.5" value={form.weekly_hours} onChange={(e) => set({ weekly_hours: e.target.value })} />
        </Field>
        <Field label="Salaire brut mensuel (FCFA)" required error={errors.base_salary?.[0]}>
          <Input type="number" step="1000" value={form.base_salary} onChange={(e) => set({ base_salary: e.target.value })} />
        </Field>
        <Field label="Poste" error={errors.position_id?.[0]}>
          <Select value={form.position_id} onChange={(e) => set({ position_id: e.target.value })}>
            <option value="">—</option>
            {positions?.map((p) => (
              <option key={p.id} value={p.id}>
                {p.title}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Département" error={errors.department_id?.[0]}>
          <Select value={form.department_id} onChange={(e) => set({ department_id: e.target.value })}>
            <option value="">—</option>
            {departments?.map((d) => (
              <option key={d.id} value={d.id}>
                {d.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field label={fixedTerm ? 'Motif du recours / missions' : 'Missions'} className="sm:col-span-2" error={errors.description?.[0]}>
          <Input value={form.description} onChange={(e) => set({ description: e.target.value })} />
        </Field>
        {save.isError && Object.keys(errors).length === 0 && (
          <div className="sm:col-span-2">
            <Alert>{errorMessage(save.error)}</Alert>
          </div>
        )}
        <div className="flex justify-end gap-2 sm:col-span-2">
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

function AmendmentModal({ contract, onClose }: { contract: Contract; onClose: () => void }) {
  const refresh = useRefreshContracts(contract.employee_id)
  const { data: positions } = usePositions()
  const { data: departments } = useDepartments()
  const [form, setForm] = useState({ start_date: '', base_salary: '', weekly_hours: '', position_id: '', department_id: '', end_date: '', description: '' })
  const set = (patch: Partial<typeof form>) => setForm({ ...form, ...patch })
  const fixedTerm = FIXED_TERM.includes(contract.type)

  const save = useMutation({
    mutationFn: () => api.post(`/contracts/${contract.id}/amendments`, Object.fromEntries(Object.entries(form).map(([k, v]) => [k, v === '' ? null : v]))),
    onSuccess: () => {
      refresh()
      onClose()
    },
  })
  const errors = validationErrors(save.error)

  return (
    <Modal open title={`Avenant au contrat ${contract.reference}`} onClose={onClose}>
      <form
        className="grid gap-4 sm:grid-cols-2"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        <p className="text-sm text-slate-500 sm:col-span-2">Renseignez uniquement ce qui change. Le poste et le département seront reportés sur le dossier à la date d'effet.</p>
        <Field label="Date d'effet" required error={errors.start_date?.[0]}>
          <Input type="date" value={form.start_date} onChange={(e) => set({ start_date: e.target.value })} />
        </Field>
        <Field label={`Nouveau salaire brut mensuel (actuel : ${formatMoney(contract.current_terms.base_salary)})`} error={errors.base_salary?.[0]}>
          <Input type="number" step="1000" value={form.base_salary} onChange={(e) => set({ base_salary: e.target.value })} />
        </Field>
        <Field label={`Nouvelle durée hebdo. (actuelle : ${contract.current_terms.weekly_hours} h)`} error={errors.weekly_hours?.[0]}>
          <Input type="number" step="0.5" value={form.weekly_hours} onChange={(e) => set({ weekly_hours: e.target.value })} />
        </Field>
        <Field label="Nouveau poste" error={errors.position_id?.[0]}>
          <Select value={form.position_id} onChange={(e) => set({ position_id: e.target.value })}>
            <option value="">Inchangé ({contract.current_terms.position ?? '—'})</option>
            {positions?.map((p) => (
              <option key={p.id} value={p.id}>
                {p.title}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Nouveau département" error={errors.department_id?.[0]}>
          <Select value={form.department_id} onChange={(e) => set({ department_id: e.target.value })}>
            <option value="">Inchangé ({contract.current_terms.department ?? '—'})</option>
            {departments?.map((d) => (
              <option key={d.id} value={d.id}>
                {d.name}
              </option>
            ))}
          </Select>
        </Field>
        {fixedTerm && (
          <Field label={`Prolongation : nouvelle date de fin (actuelle : ${formatDate(contract.effective_end_date)})`} error={errors.end_date?.[0]}>
            <Input type="date" value={form.end_date} onChange={(e) => set({ end_date: e.target.value })} />
          </Field>
        )}
        <Field label="Objet de l'avenant" className="sm:col-span-2" error={errors.description?.[0]}>
          <Input value={form.description} onChange={(e) => set({ description: e.target.value })} placeholder="ex. Promotion, passage à temps partiel…" />
        </Field>
        {save.isError && Object.keys(errors).length === 0 && (
          <div className="sm:col-span-2">
            <Alert>{errorMessage(save.error)}</Alert>
          </div>
        )}
        <div className="flex justify-end gap-2 sm:col-span-2">
          <Button type="button" variant="ghost" onClick={onClose}>
            Annuler
          </Button>
          <Button type="submit" loading={save.isPending}>
            Créer l'avenant
          </Button>
        </div>
      </form>
    </Modal>
  )
}

function EndContractModal({ contract, employee, onClose }: { contract: Contract; employee: Employee; onClose: () => void }) {
  const refresh = useRefreshContracts(contract.employee_id)
  const [form, setForm] = useState({
    ended_at: contract.effective_end_date ?? '',
    end_reason: contract.type === 'CDD' ? 'fin_cdd' : contract.type === 'stage' ? 'fin_stage' : '',
    end_notes: '',
    archive_employee: true,
  })
  const set = (patch: Partial<typeof form>) => setForm({ ...form, ...patch })

  const save = useMutation({
    mutationFn: () => api.post(`/contracts/${contract.id}/end`, form),
    onSuccess: () => {
      refresh()
      onClose()
    },
  })
  const errors = validationErrors(save.error)

  return (
    <Modal open title={`Fin du contrat ${contract.reference}`} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Dernier jour" required error={errors.ended_at?.[0]}>
            <Input type="date" value={form.ended_at} onChange={(e) => set({ ended_at: e.target.value })} />
          </Field>
          <Field label="Motif" required error={errors.end_reason?.[0]}>
            <Select value={form.end_reason} onChange={(e) => set({ end_reason: e.target.value })}>
              <option value="">Choisir…</option>
              {Object.entries(endReasonLabels).map(([v, l]) => (
                <option key={v} value={v}>
                  {l}
                </option>
              ))}
            </Select>
          </Field>
        </div>
        <Field label="Commentaire" error={errors.end_notes?.[0]}>
          <Input value={form.end_notes} onChange={(e) => set({ end_notes: e.target.value })} />
        </Field>
        {employee.status !== 'sorti' && (
          <label className="flex items-start gap-2 text-sm">
            <input type="checkbox" className="mt-1" checked={form.archive_employee} onChange={(e) => set({ archive_employee: e.target.checked })} />
            <span>
              Enregistrer aussi le départ de {employee.full_name}
              <span className="block text-xs text-slate-500">Statut « Sorti » et compte désactivé. Décochez en cas d'enchaînement sur un nouveau contrat.</span>
            </span>
          </label>
        )}
        {save.isError && Object.keys(errors).length === 0 && <Alert>{errorMessage(save.error)}</Alert>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>
            Annuler
          </Button>
          <Button type="submit" variant="danger" loading={save.isPending}>
            Terminer le contrat
          </Button>
        </div>
      </form>
    </Modal>
  )
}
