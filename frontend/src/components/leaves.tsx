import { useMutation, useQuery } from '@tanstack/react-query'
import { Check, Paperclip, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { api, errorMessage, validationErrors } from '../api/client'
import { useEmployeeOptions, useLeaveTypes } from '../api/queries'
import type { LeaveBalance, LeavePreview, LeaveRequest } from '../api/types'
import { useInvalidateLeaves } from '../api/useInvalidateLeaves'
import { useAuth } from '../auth/useAuth'
import { formatDays, formatPeriod, leaveStatusColors, leaveStatusLabels } from '../lib/labels'
import { Alert, Badge, Button, Field, Input, Modal, Select } from './ui'

export function LeaveStatusBadge({ status }: { status: LeaveRequest['status'] }) {
  return <Badge className={leaveStatusColors[status]}>{leaveStatusLabels[status]}</Badge>
}

export function TypeDot({ color }: { color: string }) {
  return <span className="inline-block h-2.5 w-2.5 shrink-0 rounded-full" style={{ backgroundColor: color }} />
}

export function BalanceCards({ balances }: { balances: LeaveBalance[] }) {
  if (balances.length === 0) return <p className="text-sm text-slate-500">Aucun type de congé décompté.</p>

  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {balances.map((b) => (
        <div key={b.leave_type_id} className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
          <div className="flex items-center gap-2 text-sm font-medium text-slate-600">
            <TypeDot color={b.color} /> {b.leave_type}
          </div>
          <div className="mt-2 text-3xl font-semibold text-slate-900">{formatDays(b.remaining)}</div>
          <div className="text-xs text-slate-500">disponibles</div>
          <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-slate-600">
            <dt>Acquis</dt>
            <dd className="text-right">{formatDays(b.accrued)}</dd>
            {b.adjustments !== 0 && (
              <>
                <dt>Ajustements</dt>
                <dd className="text-right">{b.adjustments > 0 ? '+' : ''}{formatDays(b.adjustments)}</dd>
              </>
            )}
            <dt>Pris</dt>
            <dd className="text-right">{formatDays(b.taken)}</dd>
            <dt>En attente</dt>
            <dd className="text-right">{formatDays(b.pending)}</dd>
          </dl>
        </div>
      ))}
    </div>
  )
}

/**
 * Formulaire de demande (employé) ou de déclaration d'absence (RH, avec choix de l'employé).
 * Le nombre de jours et le solde sont recalculés par l'API à chaque changement.
 */
export function LeaveRequestModal({ open, onClose, employeeId }: { open: boolean; onClose: () => void; employeeId?: number }) {
  const { isHr, user } = useAuth()
  const { data: types } = useLeaveTypes()
  const { data: employees } = useEmployeeOptions()
  const invalidate = useInvalidateLeaves()

  const [form, setForm] = useState({
    employee_id: employeeId ? String(employeeId) : '',
    leave_type_id: '',
    start_date: '',
    end_date: '',
    start_half_day: false,
    end_half_day: false,
    reason: '',
  })
  const [file, setFile] = useState<File | null>(null)
  const set = (patch: Partial<typeof form>) => setForm((f) => ({ ...f, ...patch }))

  const forOther = isHr && form.employee_id !== '' && Number(form.employee_id) !== user?.employee_id
  const availableTypes = types?.filter((t) => t.employee_can_request || isHr)
  const selectedType = types?.find((t) => String(t.id) === form.leave_type_id)
  const singleDay = form.start_date !== '' && form.start_date === form.end_date

  const payload = {
    ...form,
    employee_id: form.employee_id || undefined,
    start_half_day: form.start_half_day ? 1 : 0,
    end_half_day: form.end_half_day ? 1 : 0,
  }
  const ready = form.leave_type_id && form.start_date && form.end_date && form.end_date >= form.start_date

  // Aperçu différé : jours ouvrés, solde restant, chevauchement
  const [debounced, setDebounced] = useState(payload)
  useEffect(() => {
    const t = setTimeout(() => setDebounced(payload), 250)
    return () => clearTimeout(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [JSON.stringify(payload)])

  const preview = useQuery({
    queryKey: ['leave-preview', debounced],
    queryFn: async () => (await api.post<LeavePreview>('/leave-requests/preview', debounced)).data,
    enabled: open && !!ready,
    retry: false,
  })

  const submit = useMutation({
    mutationFn: async () => {
      const data = new FormData()
      Object.entries(payload).forEach(([k, v]) => v !== undefined && v !== '' && data.append(k, String(v)))
      if (file) data.append('attachment', file)
      return (await api.post<{ data: LeaveRequest }>('/leave-requests', data)).data.data
    },
    onSuccess: () => {
      invalidate()
      onClose()
    },
  })
  const errors = validationErrors(submit.error)
  const previewErrors = preview.data?.errors ?? {}
  const err = (k: string) => errors[k]?.[0] ?? previewErrors[k]

  return (
    <Modal open={open} title={isHr && !employeeId ? 'Nouvelle demande ou absence' : 'Nouvelle demande de congé'} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          submit.mutate()
        }}
      >
        {isHr && !employeeId && (
          <Field label="Employé" error={err('employee_id')}>
            <Select value={form.employee_id} onChange={(e) => set({ employee_id: e.target.value })}>
              <option value="">Moi-même</option>
              {employees
                ?.filter((o) => o.id !== user?.employee_id)
                .map((o) => (
                  <option key={o.id} value={o.id}>
                    {o.label}
                  </option>
                ))}
            </Select>
          </Field>
        )}

        <Field label="Type" required error={err('leave_type_id')}>
          <Select value={form.leave_type_id} onChange={(e) => set({ leave_type_id: e.target.value })}>
            <option value="">Choisir…</option>
            {availableTypes?.map((t) => (
              <option key={t.id} value={t.id}>
                {t.name}
              </option>
            ))}
          </Select>
        </Field>

        <div className="grid grid-cols-2 gap-4">
          <Field label="Du" required error={err('start_date')}>
            <Input
              type="date"
              value={form.start_date}
              onChange={(e) => set({ start_date: e.target.value, end_date: form.end_date < e.target.value ? e.target.value : form.end_date })}
            />
          </Field>
          <Field label="Au" required error={err('end_date')}>
            <Input type="date" min={form.start_date} value={form.end_date} onChange={(e) => set({ end_date: e.target.value })} />
          </Field>
        </div>

        <div className="flex flex-wrap gap-x-6 gap-y-2 text-sm text-slate-700">
          <label className="flex items-center gap-2">
            <input type="checkbox" checked={form.start_half_day} onChange={(e) => set({ start_half_day: e.target.checked, end_half_day: singleDay && e.target.checked ? false : form.end_half_day })} />
            {singleDay ? 'Après-midi seulement' : "Départ l'après-midi"}
          </label>
          <label className="flex items-center gap-2">
            <input type="checkbox" checked={form.end_half_day} onChange={(e) => set({ end_half_day: e.target.checked, start_half_day: singleDay && e.target.checked ? false : form.start_half_day })} />
            {singleDay ? 'Matin seulement' : 'Retour à midi (dernier jour le matin)'}
          </label>
        </div>

        <Field label="Commentaire" error={err('reason')}>
          <textarea
            className="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-100 focus:outline-none"
            rows={2}
            value={form.reason}
            onChange={(e) => set({ reason: e.target.value })}
          />
        </Field>

        {(selectedType?.requires_attachment || file) && (
          <Field label="Justificatif (PDF, JPG, PNG — 10 Mo max)" required={selectedType?.requires_attachment && !isHr} error={err('attachment')}>
            <input type="file" accept=".pdf,.jpg,.jpeg,.png" className="text-sm" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
          </Field>
        )}

        {ready && preview.data && (
          <div className="rounded-md bg-slate-50 px-4 py-3 text-sm">
            <strong>{formatDays(preview.data.days)}</strong> ouvré(s)
            {preview.data.remaining !== null && (
              <span className="text-slate-500">
                {' '}
                · solde disponible : {formatDays(preview.data.remaining)}
                {preview.data.remaining >= preview.data.days && ` → ${formatDays(preview.data.remaining - preview.data.days)} après la demande`}
              </span>
            )}
            {forOther && <div className="mt-1 text-slate-500">Saisie RH : l'absence sera enregistrée directement comme approuvée.</div>}
          </div>
        )}

        {submit.isError && Object.keys(errors).length === 0 && <Alert>{errorMessage(submit.error)}</Alert>}

        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>
            Annuler
          </Button>
          <Button type="submit" loading={submit.isPending} disabled={!ready}>
            {forOther ? "Enregistrer l'absence" : 'Envoyer la demande'}
          </Button>
        </div>
      </form>
    </Modal>
  )
}

/** Tableau des demandes avec les actions autorisées (valider, refuser, annuler). */
export function LeaveRequestList({ requests, showEmployee = false, emptyText }: { requests: LeaveRequest[]; showEmployee?: boolean; emptyText: string }) {
  const invalidate = useInvalidateLeaves()
  const [rejecting, setRejecting] = useState<LeaveRequest | null>(null)
  const [error, setError] = useState<string | null>(null)

  const act = useMutation({
    mutationFn: ({ id, action }: { id: number; action: 'approve' | 'cancel' }) => api.post(`/leave-requests/${id}/${action}`),
    onSuccess: () => {
      setError(null)
      invalidate()
    },
    onError: (e) => setError(errorMessage(e)),
  })

  if (requests.length === 0) return <p className="py-6 text-center text-sm text-slate-500">{emptyText}</p>

  return (
    <>
      {error && (
        <div className="mb-3">
          <Alert>{error}</Alert>
        </div>
      )}
      <div className="overflow-x-auto">
        <table className="min-w-full divide-y divide-slate-200 text-sm">
          <thead className="text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
            <tr>
              {showEmployee && <th className="py-2 pr-4">Employé</th>}
              <th className="py-2 pr-4">Type</th>
              <th className="py-2 pr-4">Période</th>
              <th className="py-2 pr-4">Durée</th>
              <th className="py-2 pr-4">Statut</th>
              <th className="py-2" />
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {requests.map((r) => (
              <tr key={r.id} className="align-top">
                {showEmployee && <td className="py-3 pr-4 font-medium">{r.employee?.full_name}</td>}
                <td className="py-3 pr-4">
                  <span className="flex items-center gap-2">
                    {r.leave_type && <TypeDot color={r.leave_type.color} />}
                    {r.leave_type?.name}
                    {r.has_attachment && (
                      <a href={`/api/v1/leave-requests/${r.id}/attachment`} className="text-slate-400 hover:text-brand-700" title="Justificatif">
                        <Paperclip className="h-3.5 w-3.5" />
                      </a>
                    )}
                  </span>
                  {r.reason && <div className="mt-1 text-xs text-slate-500">« {r.reason} »</div>}
                </td>
                <td className="py-3 pr-4 whitespace-nowrap">{formatPeriod(r)}</td>
                <td className="py-3 pr-4 whitespace-nowrap">{formatDays(r.days)}</td>
                <td className="py-3 pr-4">
                  <LeaveStatusBadge status={r.status} />
                  {r.refusal_reason && <div className="mt-1 text-xs text-rose-700">{r.refusal_reason}</div>}
                </td>
                <td className="py-3 text-right whitespace-nowrap">
                  {r.can.decide && (
                    <>
                      <Button variant="secondary" className="mr-2 px-2.5 py-1.5" onClick={() => act.mutate({ id: r.id, action: 'approve' })} loading={act.isPending && act.variables?.id === r.id}>
                        <Check className="h-4 w-4 text-emerald-600" /> Valider
                      </Button>
                      <Button variant="secondary" className="px-2.5 py-1.5" onClick={() => setRejecting(r)}>
                        <X className="h-4 w-4 text-rose-600" /> Refuser
                      </Button>
                    </>
                  )}
                  {r.can.cancel && !r.can.decide && (
                    <Button variant="ghost" className="px-2.5 py-1.5" onClick={() => confirm('Annuler cette demande ?') && act.mutate({ id: r.id, action: 'cancel' })}>
                      Annuler
                    </Button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {rejecting && <RejectModal request={rejecting} onClose={() => setRejecting(null)} />}
    </>
  )
}

function RejectModal({ request, onClose }: { request: LeaveRequest; onClose: () => void }) {
  const invalidate = useInvalidateLeaves()
  const [reason, setReason] = useState('')
  const reject = useMutation({
    mutationFn: () => api.post(`/leave-requests/${request.id}/reject`, { reason }),
    onSuccess: () => {
      invalidate()
      onClose()
    },
  })

  return (
    <Modal open title={`Refuser la demande de ${request.employee?.full_name ?? ''}`} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          reject.mutate()
        }}
      >
        <p className="text-sm text-slate-600">
          {request.leave_type?.name} · {formatPeriod(request)} · {formatDays(request.days)}
        </p>
        <Field label="Motif du refus" required error={validationErrors(reject.error).reason?.[0]}>
          <Input autoFocus value={reason} onChange={(e) => setReason(e.target.value)} />
        </Field>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>
            Retour
          </Button>
          <Button type="submit" variant="danger" loading={reject.isPending}>
            Refuser
          </Button>
        </div>
      </form>
    </Modal>
  )
}
