import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { api, errorMessage, validationErrors } from '../../api/client'
import { useLeaveTypes } from '../../api/queries'
import type { LeaveType, PublicHoliday } from '../../api/types'
import { TypeDot } from '../../components/leaves'
import { Alert, Badge, Button, Card, Field, Input, Modal, PageHeader, Select, Spinner } from '../../components/ui'
import { THIS_YEAR, formatDate } from '../../lib/labels'

type TypeForm = Omit<LeaveType, 'id' | 'monthly_accrual' | 'annual_allowance'> & { id?: number; monthly_accrual: string; annual_allowance: string }

const emptyType: TypeForm = {
  name: '',
  code: '',
  color: '#2e75b6',
  is_deductible: true,
  monthly_accrual: '',
  annual_allowance: '',
  requires_attachment: false,
  requires_hr_approval: false,
  employee_can_request: true,
  is_active: true,
}

function TypeModal({ initial, onClose }: { initial: TypeForm; onClose: () => void }) {
  const queryClient = useQueryClient()
  const [form, setForm] = useState(initial)
  const set = (patch: Partial<TypeForm>) => setForm({ ...form, ...patch })

  const save = useMutation({
    mutationFn: () => {
      const payload = { ...form, monthly_accrual: form.monthly_accrual || null, annual_allowance: form.annual_allowance || null }
      return form.id ? api.put(`/leave-types/${form.id}`, payload) : api.post('/leave-types', payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['leave-types'] })
      onClose()
    },
  })
  const errors = validationErrors(save.error)

  const checkbox = (key: keyof TypeForm, label: string) => (
    <label className="flex items-center gap-2 text-sm">
      <input type="checkbox" checked={form[key] as boolean} onChange={(e) => set({ [key]: e.target.checked })} /> {label}
    </label>
  )

  return (
    <Modal open title={form.id ? 'Modifier le type de congé' : 'Nouveau type de congé'} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        <div className="grid grid-cols-[1fr_7rem_4rem] gap-3">
          <Field label="Nom" required error={errors.name?.[0]}>
            <Input value={form.name} onChange={(e) => set({ name: e.target.value })} />
          </Field>
          <Field label="Code" required error={errors.code?.[0]}>
            <Input value={form.code} onChange={(e) => set({ code: e.target.value.toUpperCase() })} />
          </Field>
          <Field label="Couleur" error={errors.color?.[0]}>
            <Input type="color" className="h-[38px] p-1" value={form.color} onChange={(e) => set({ color: e.target.value })} />
          </Field>
        </div>
        {checkbox('is_deductible', 'Décompté d’un solde')}
        {form.is_deductible && (
          <div className="grid grid-cols-2 gap-3">
            <Field label="Acquisition mensuelle (jours)" error={errors.monthly_accrual?.[0]}>
              <Input type="number" step="0.01" min={0} value={form.monthly_accrual} onChange={(e) => set({ monthly_accrual: e.target.value })} placeholder="ex. 2,08" />
            </Field>
            <Field label="Droits annuels (jours)" error={errors.annual_allowance?.[0]}>
              <Input type="number" step="0.5" min={0} value={form.annual_allowance} onChange={(e) => set({ annual_allowance: e.target.value })} placeholder="ex. 25" />
            </Field>
          </div>
        )}
        <div className="space-y-2">
          {checkbox('employee_can_request', 'Peut être demandé par l’employé (sinon saisi par les RH)')}
          {checkbox('requires_attachment', 'Justificatif obligatoire')}
          {checkbox('requires_hr_approval', 'Validation RH en plus du manager')}
          {checkbox('is_active', 'Actif')}
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

function Holidays() {
  const queryClient = useQueryClient()
  const [year, setYear] = useState(THIS_YEAR)
  const [form, setForm] = useState({ date: '', name: '' })

  const { data, isLoading } = useQuery({
    queryKey: ['public-holidays', year],
    queryFn: async () => (await api.get<PublicHoliday[]>(`/public-holidays?year=${year}`)).data,
  })
  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['public-holidays'] })
    queryClient.invalidateQueries({ queryKey: ['leave-calendar'] })
  }

  const generate = useMutation({ mutationFn: () => api.post('/public-holidays/generate', { year }), onSuccess: refresh })
  const add = useMutation({
    mutationFn: () => api.post('/public-holidays', form),
    onSuccess: () => {
      setForm({ date: '', name: '' })
      refresh()
    },
  })
  const remove = useMutation({ mutationFn: (id: number) => api.delete(`/public-holidays/${id}`), onSuccess: refresh })
  const addErrors = validationErrors(add.error)

  return (
    <Card
      title="Jours fériés"
      actions={
        <div className="flex gap-2">
          <Select value={year} onChange={(e) => setYear(Number(e.target.value))} className="w-28">
            {[THIS_YEAR - 1, THIS_YEAR, THIS_YEAR + 1, THIS_YEAR + 2].map((y) => (
              <option key={y}>{y}</option>
            ))}
          </Select>
          <Button variant="secondary" loading={generate.isPending} onClick={() => generate.mutate()}>
            Jours fériés du Bénin
          </Button>
        </div>
      }
    >
      {isLoading ? (
        <Spinner />
      ) : (
        <ul className="divide-y divide-slate-100 text-sm">
          {data?.map((h) => (
            <li key={h.id} className="flex items-center justify-between py-2">
              <span>
                <span className="inline-block w-28 text-slate-500">{formatDate(h.date)}</span>
                {h.name}
              </span>
              <button className="p-1 text-slate-400 hover:text-rose-600" aria-label="Supprimer" onClick={() => remove.mutate(h.id)}>
                <Trash2 className="h-4 w-4" />
              </button>
            </li>
          ))}
          {data?.length === 0 && <li className="py-4 text-slate-500">Aucun jour férié saisi pour {year}.</li>}
        </ul>
      )}
      <form
        className="mt-4 flex flex-wrap items-start gap-2"
        onSubmit={(e) => {
          e.preventDefault()
          add.mutate()
        }}
      >
        <Field label="" error={addErrors.date?.[0]}>
          <Input type="date" value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} />
        </Field>
        <Field label="" error={addErrors.name?.[0]} className="flex-1">
          <Input placeholder="Libellé (ex. journée de solidarité)" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        </Field>
        <Button type="submit" variant="secondary" loading={add.isPending}>
          <Plus className="h-4 w-4" /> Ajouter
        </Button>
      </form>
    </Card>
  )
}

export default function LeaveSettingsPage() {
  const { data: types, isLoading } = useLeaveTypes(true)
  const [editing, setEditing] = useState<TypeForm | null>(null)

  return (
    <>
      <PageHeader title="Paramètres des congés" subtitle="Types de congés et jours fériés" />
      <div className="grid gap-6 xl:grid-cols-[3fr_2fr]">
        <Card
          title="Types de congés"
          actions={
            <Button variant="secondary" onClick={() => setEditing(emptyType)}>
              <Plus className="h-4 w-4" /> Ajouter
            </Button>
          }
        >
          {isLoading ? (
            <Spinner />
          ) : (
            <table className="min-w-full text-sm">
              <thead className="text-left text-xs text-slate-500 uppercase">
                <tr>
                  <th className="pb-2">Type</th>
                  <th className="pb-2">Droits</th>
                  <th className="pb-2">Règles</th>
                  <th />
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {types?.map((t) => (
                  <tr key={t.id} className={t.is_active ? '' : 'opacity-50'}>
                    <td className="py-2 pr-3">
                      <span className="flex items-center gap-2">
                        <TypeDot color={t.color} /> {t.name} <span className="font-mono text-xs text-slate-400">{t.code}</span>
                      </span>
                    </td>
                    <td className="py-2 pr-3 text-slate-600">
                      {!t.is_deductible
                        ? 'Non décompté'
                        : t.monthly_accrual
                          ? `${t.monthly_accrual} j/mois${t.annual_allowance ? ` (max ${t.annual_allowance})` : ''}`
                          : `${t.annual_allowance ?? 0} j/an`}
                    </td>
                    <td className="space-x-1 py-2 pr-3">
                      {!t.employee_can_request && <Badge className="bg-slate-100 text-slate-700">Saisie RH</Badge>}
                      {t.requires_attachment && <Badge className="bg-slate-100 text-slate-700">Justificatif</Badge>}
                      {t.requires_hr_approval && <Badge className="bg-slate-100 text-slate-700">Validation RH</Badge>}
                      {!t.is_active && <Badge className="bg-slate-100 text-slate-700">Inactif</Badge>}
                    </td>
                    <td className="py-2 text-right">
                      <button
                        className="p-1 text-slate-400 hover:text-brand-700"
                        aria-label="Modifier"
                        onClick={() =>
                          setEditing({ ...t, monthly_accrual: t.monthly_accrual?.toString() ?? '', annual_allowance: t.annual_allowance?.toString() ?? '' })
                        }
                      >
                        <Pencil className="h-4 w-4" />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
        <Holidays />
      </div>
      {editing && <TypeModal initial={editing} onClose={() => setEditing(null)} />}
    </>
  )
}
