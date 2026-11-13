import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Pencil, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { api, errorMessage, validationErrors } from '../api/client'
import { useDepartments, useEmployeeOptions, usePositions, useSites } from '../api/queries'
import { Alert, Button, Card, Field, Input, Modal, PageHeader, Select, Spinner } from '../components/ui'

type Kind = 'departments' | 'positions' | 'sites'
type Values = Record<string, string>

interface FieldDef {
  name: string
  label: string
  type?: 'text' | 'number' | 'select'
  options?: { value: string | number; label: string }[]
  required?: boolean
}

/** Formulaire générique (création / modification) pour un élément de la structure. */
function EditModal({ kind, title, fields, initial, onClose }: { kind: Kind; title: string; fields: FieldDef[]; initial: Values & { id?: string }; onClose: () => void }) {
  const queryClient = useQueryClient()
  const [values, setValues] = useState<Values>(initial)

  const save = useMutation({
    mutationFn: () => {
      const payload = Object.fromEntries(Object.entries(values).map(([k, v]) => [k, v === '' ? null : v]))
      return initial.id ? api.put(`/${kind}/${initial.id}`, payload) : api.post(`/${kind}`, payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [kind] })
      onClose()
    },
  })
  const errors = validationErrors(save.error)

  return (
    <Modal open title={title} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        {fields.map((f) => (
          <Field key={f.name} label={f.label} required={f.required} error={errors[f.name]?.[0]}>
            {f.type === 'select' ? (
              <Select value={values[f.name] ?? ''} onChange={(e) => setValues({ ...values, [f.name]: e.target.value })}>
                <option value="">—</option>
                {f.options?.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </Select>
            ) : (
              <Input
                type={f.type ?? 'text'}
                value={values[f.name] ?? ''}
                onChange={(e) => setValues({ ...values, [f.name]: e.target.value })}
              />
            )}
          </Field>
        ))}
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

interface Column<T> {
  label: string
  render: (row: T) => React.ReactNode
}

function Section<T extends { id: number; employees_count: number }>({
  kind,
  title,
  rows,
  columns,
  fields,
  toValues,
  loading,
}: {
  kind: Kind
  title: string
  rows?: T[]
  columns: Column<T>[]
  fields: FieldDef[]
  toValues: (row?: T) => Values
  loading: boolean
}) {
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState<(Values & { id?: string }) | null>(null)

  const remove = useMutation({
    mutationFn: (id: number) => api.delete(`/${kind}/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [kind] }),
  })

  return (
    <Card
      title={title}
      actions={
        <Button variant="secondary" onClick={() => setEditing(toValues())}>
          <Plus className="h-4 w-4" /> Ajouter
        </Button>
      }
    >
      {remove.isError && (
        <div className="mb-3">
          <Alert>{errorMessage(remove.error)}</Alert>
        </div>
      )}
      {loading ? (
        <Spinner />
      ) : (
        <table className="min-w-full text-sm">
          <thead className="text-left text-xs text-slate-500 uppercase">
            <tr>
              {columns.map((c) => (
                <th key={c.label} className="pb-2">
                  {c.label}
                </th>
              ))}
              <th className="pb-2">Effectif</th>
              <th />
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {rows?.map((row) => (
              <tr key={row.id}>
                {columns.map((c) => (
                  <td key={c.label} className="py-2 pr-3">
                    {c.render(row)}
                  </td>
                ))}
                <td className="py-2">{row.employees_count}</td>
                <td className="py-2 text-right whitespace-nowrap">
                  <button className="p-1 text-slate-400 hover:text-brand-700" aria-label="Modifier" onClick={() => setEditing({ ...toValues(row), id: String(row.id) })}>
                    <Pencil className="h-4 w-4" />
                  </button>
                  <button
                    className="p-1 text-slate-400 hover:text-rose-600"
                    aria-label="Supprimer"
                    onClick={() => confirm('Supprimer cet élément ?') && remove.mutate(row.id)}
                  >
                    <Trash2 className="h-4 w-4" />
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {editing && <EditModal kind={kind} title={editing.id ? 'Modifier' : 'Ajouter'} fields={fields} initial={editing} onClose={() => setEditing(null)} />}
    </Card>
  )
}

const s = (v: unknown) => (v === null || v === undefined ? '' : String(v))

export default function OrganisationPage() {
  const departments = useDepartments()
  const positions = usePositions()
  const sites = useSites()
  const employees = useEmployeeOptions()

  return (
    <>
      <PageHeader title="Organisation" subtitle="Départements, postes et sites de l'entreprise" />
      <div className="space-y-6">
        <Section
          kind="departments"
          title="Départements"
          loading={departments.isLoading}
          rows={departments.data}
          columns={[
            { label: 'Code', render: (d) => <span className="font-mono text-xs">{d.code}</span> },
            { label: 'Nom', render: (d) => d.name },
            { label: 'Rattaché à', render: (d) => d.parent?.name ?? '—' },
            { label: 'Responsable', render: (d) => (d.manager ? `${d.manager.first_name} ${d.manager.last_name}` : '—') },
          ]}
          fields={[
            { name: 'name', label: 'Nom', required: true },
            { name: 'code', label: 'Code', required: true },
            { name: 'parent_id', label: 'Département parent', type: 'select', options: departments.data?.map((d) => ({ value: d.id, label: d.name })) },
            { name: 'manager_id', label: 'Responsable', type: 'select', options: employees.data?.map((e) => ({ value: e.id, label: e.label })) },
          ]}
          toValues={(d) => ({ name: s(d?.name), code: s(d?.code), parent_id: s(d?.parent_id), manager_id: s(d?.manager_id) })}
        />

        <div className="grid gap-6 xl:grid-cols-2">
          <Section
            kind="positions"
            title="Postes"
            loading={positions.isLoading}
            rows={positions.data}
            columns={[
              { label: 'Intitulé', render: (p) => p.title },
              { label: 'Catégorie', render: (p) => p.category ?? '—' },
            ]}
            fields={[
              { name: 'title', label: 'Intitulé', required: true },
              { name: 'category', label: 'Catégorie' },
              { name: 'min_salary', label: 'Salaire minimum (€ brut annuel)', type: 'number' },
              { name: 'max_salary', label: 'Salaire maximum (€ brut annuel)', type: 'number' },
            ]}
            toValues={(p) => ({ title: s(p?.title), category: s(p?.category), min_salary: s(p?.min_salary), max_salary: s(p?.max_salary) })}
          />

          <Section
            kind="sites"
            title="Sites"
            loading={sites.isLoading}
            rows={sites.data}
            columns={[
              { label: 'Nom', render: (x) => x.name },
              { label: 'Ville', render: (x) => x.city ?? '—' },
            ]}
            fields={[
              { name: 'name', label: 'Nom', required: true },
              { name: 'address', label: 'Adresse' },
              { name: 'city', label: 'Ville' },
              { name: 'country', label: 'Pays (code ISO, ex. FR)' },
            ]}
            toValues={(x) => ({ name: s(x?.name), address: s(x?.address), city: s(x?.city), country: s(x?.country ?? 'FR') })}
          />
        </div>
      </div>
    </>
  )
}
