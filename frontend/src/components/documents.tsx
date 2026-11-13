import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FileImage, FileText, Plus, Sparkles, Trash2, Upload } from 'lucide-react'
import { useState } from 'react'
import { api, errorMessage, validationErrors } from '../api/client'
import { useDocumentCategories } from '../api/queries'
import type { Employee, EmployeeDocument } from '../api/types'
import { useAuth } from '../auth/useAuth'
import { daysUntil, formatDate, formatSize } from '../lib/labels'
import { Alert, Badge, Button, Card, Field, Input, Modal, Select, Spinner } from './ui'

function useRefreshDocuments() {
  const queryClient = useQueryClient()
  return () => {
    for (const key of ['documents', 'document-expiring', 'document-requests', 'document-categories']) {
      queryClient.invalidateQueries({ queryKey: [key] })
    }
  }
}

/** Badge d'expiration : rouge si expiré ou à moins de 7 jours, orange à moins de 30 jours. */
export function ExpiryBadge({ date }: { date: string | null }) {
  if (!date) return null
  const days = daysUntil(date)
  if (days < 0) return <Badge className="bg-rose-100 text-rose-800">Expiré le {formatDate(date)}</Badge>
  if (days <= 7) return <Badge className="bg-rose-100 text-rose-800">Expire dans {days} j</Badge>
  if (days <= 30) return <Badge className="bg-amber-100 text-amber-800">Expire le {formatDate(date)}</Badge>
  return <span className="text-xs text-slate-500">Valable jusqu'au {formatDate(date)}</span>
}

export function FileIcon({ mime }: { mime: string }) {
  const Icon = mime.startsWith('image/') ? FileImage : FileText
  return <Icon className="h-5 w-5 shrink-0 text-slate-400" />
}

/** Onglet « Documents » de la fiche employé, et coffre-fort de « Mes documents » (DOC-01). */
export function EmployeeDocuments({ employee }: { employee: Pick<Employee, 'id' | 'full_name' | 'status'> }) {
  const { isHr } = useAuth()
  const refresh = useRefreshDocuments()
  const [uploading, setUploading] = useState(false)

  const { data, isLoading } = useQuery({
    queryKey: ['documents', employee.id],
    queryFn: async () => (await api.get<EmployeeDocument[]>(`/employees/${employee.id}/documents`)).data,
  })

  const remove = useMutation({ mutationFn: (id: number) => api.delete(`/documents/${id}`), onSuccess: refresh })
  const generate = useMutation({
    mutationFn: (type: string) => api.post(`/employees/${employee.id}/documents/generate`, { type }),
    onSuccess: refresh,
  })

  if (isLoading) return <Spinner />

  // Regroupement par catégorie
  const groups = new Map<string, EmployeeDocument[]>()
  for (const d of data ?? []) groups.set(d.category.name, [...(groups.get(d.category.name) ?? []), d])

  return (
    <div className="space-y-4">
      {isHr && (
        <div className="flex flex-wrap justify-end gap-2">
          {employee.status !== 'sorti' && (
            <>
              <Button variant="secondary" loading={generate.isPending && generate.variables === 'attestation_travail'} onClick={() => generate.mutate('attestation_travail')}>
                <Sparkles className="h-4 w-4" /> Attestation de travail
              </Button>
              <Button variant="secondary" loading={generate.isPending && generate.variables === 'attestation_salaire'} onClick={() => generate.mutate('attestation_salaire')}>
                <Sparkles className="h-4 w-4" /> Attestation de salaire
              </Button>
            </>
          )}
          <Button onClick={() => setUploading(true)}>
            <Upload className="h-4 w-4" /> Déposer un document
          </Button>
        </div>
      )}

      {(generate.isError || remove.isError) && <Alert>{errorMessage(generate.error ?? remove.error)}</Alert>}

      {groups.size === 0 && (
        <Card>
          <p className="text-sm text-slate-500">Aucun document.</p>
        </Card>
      )}

      {[...groups.entries()].map(([category, docs]) => (
        <Card key={category} title={category}>
          <ul className="divide-y divide-slate-100">
            {docs.map((d) => (
              <li key={d.id} className="flex flex-wrap items-center gap-3 py-2.5">
                <FileIcon mime={d.mime_type} />
                <div className="min-w-0 flex-1">
                  <a href={d.url} target="_blank" rel="noreferrer" className="font-medium text-brand-700 hover:underline">
                    {d.title}
                  </a>
                  <div className="text-xs text-slate-500">
                    {d.source === 'genere' ? 'Généré' : 'Déposé'} le {formatDate(d.created_at.slice(0, 10))}
                    {d.uploaded_by && ` par ${d.uploaded_by}`} · {formatSize(d.size)}
                  </div>
                  {d.notes && <div className="text-xs text-slate-500">{d.notes}</div>}
                </div>
                <ExpiryBadge date={d.expires_at} />
                {isHr && (
                  <button
                    className="p-1 text-slate-400 hover:text-rose-600"
                    aria-label="Supprimer"
                    onClick={() => confirm(`Supprimer « ${d.title} » ? Le fichier sera effacé.`) && remove.mutate(d.id)}
                  >
                    <Trash2 className="h-4 w-4" />
                  </button>
                )}
              </li>
            ))}
          </ul>
        </Card>
      ))}

      {uploading && <UploadModal employee={employee} onClose={() => setUploading(false)} />}
    </div>
  )
}

function UploadModal({ employee, onClose }: { employee: Pick<Employee, 'id' | 'full_name'>; onClose: () => void }) {
  const refresh = useRefreshDocuments()
  const { data: categories } = useDocumentCategories()
  const [form, setForm] = useState({ document_category_id: '', title: '', expires_at: '', notes: '' })
  const [file, setFile] = useState<File | null>(null)
  const category = categories?.find((c) => String(c.id) === form.document_category_id)

  const save = useMutation({
    mutationFn: () => {
      const data = new FormData()
      Object.entries(form).forEach(([k, v]) => v && data.append(k, v))
      if (file) data.append('file', file)
      return api.post(`/employees/${employee.id}/documents`, data)
    },
    onSuccess: () => {
      refresh()
      onClose()
    },
  })
  const errors = validationErrors(save.error)

  return (
    <Modal open title={`Déposer un document — ${employee.full_name}`} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        <Field label="Catégorie" required error={errors.document_category_id?.[0]}>
          <Select value={form.document_category_id} onChange={(e) => setForm({ ...form, document_category_id: e.target.value })}>
            <option value="">Choisir…</option>
            {categories?.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </Select>
        </Field>
        {category && (
          <p className="-mt-2 text-xs text-slate-500">
            Visible par : RH{category.visible_to_employee && ', l’employé'}
            {category.visible_to_manager && ', son manager'}.
          </p>
        )}
        <Field label="Fichier (PDF, JPG, PNG, Word — 10 Mo max.)" required error={errors.file?.[0]}>
          <input
            type="file"
            accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
            className="text-sm"
            onChange={(e) => {
              const f = e.target.files?.[0] ?? null
              setFile(f)
              if (f && !form.title) setForm((prev) => ({ ...prev, title: f.name.replace(/\.[^.]+$/, '') }))
            }}
          />
        </Field>
        <Field label="Titre" error={errors.title?.[0]}>
          <Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
        </Field>
        {category?.has_expiry && (
          <Field label="Date d'expiration" error={errors.expires_at?.[0]}>
            <Input type="date" value={form.expires_at} onChange={(e) => setForm({ ...form, expires_at: e.target.value })} />
          </Field>
        )}
        <Field label="Note" error={errors.notes?.[0]}>
          <Input value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
        </Field>
        {save.isError && Object.keys(errors).length === 0 && <Alert>{errorMessage(save.error)}</Alert>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>
            Annuler
          </Button>
          <Button type="submit" loading={save.isPending} disabled={!file || !form.document_category_id}>
            <Plus className="h-4 w-4" /> Déposer
          </Button>
        </div>
      </form>
    </Modal>
  )
}
