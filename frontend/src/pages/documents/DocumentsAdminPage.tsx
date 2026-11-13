import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Plus, Sparkles, Trash2, Upload, Users, X } from 'lucide-react'
import { useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, errorMessage, validationErrors } from '../../api/client'
import { useDocumentCategories } from '../../api/queries'
import type { CompanyDocumentItem, DocumentCategory, DocumentRequestItem, EmployeeDocument } from '../../api/types'
import { ExpiryBadge, FileIcon } from '../../components/documents'
import { Alert, Badge, Button, Card, Field, Input, Modal, PageHeader, Spinner } from '../../components/ui'
import { formatDate, formatDateTime } from '../../lib/labels'

/** DOC-04 : demandes à traiter — générer, déposer le fichier, ou refuser. */
function PendingRequests() {
  const queryClient = useQueryClient()
  const fileInput = useRef<HTMLInputElement>(null)
  const [target, setTarget] = useState<DocumentRequestItem | null>(null)
  const [refusing, setRefusing] = useState<DocumentRequestItem | null>(null)
  const [reason, setReason] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['document-requests', 'pending'],
    queryFn: async () => (await api.get<DocumentRequestItem[]>('/document-requests?scope=all&status=en_attente')).data,
  })
  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['document-requests'] })
    queryClient.invalidateQueries({ queryKey: ['documents'] })
  }

  const fulfill = useMutation({
    mutationFn: ({ request, file }: { request: DocumentRequestItem; file?: File }) => {
      const data = new FormData()
      if (file) data.append('file', file)
      return api.post(`/document-requests/${request.id}/fulfill`, data)
    },
    onSuccess: refresh,
  })
  const refuse = useMutation({
    mutationFn: () => api.post(`/document-requests/${refusing!.id}/refuse`, { reason }),
    onSuccess: () => {
      setRefusing(null)
      setReason('')
      refresh()
    },
  })

  return (
    <Card title={`Demandes à traiter${data?.length ? ` (${data.length})` : ''}`}>
      <input
        ref={fileInput}
        type="file"
        hidden
        accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
        onChange={(e) => {
          const file = e.target.files?.[0]
          if (file && target) fulfill.mutate({ request: target, file })
          e.target.value = ''
        }}
      />
      {fulfill.isError && (
        <div className="mb-3">
          <Alert>{errorMessage(fulfill.error)}</Alert>
        </div>
      )}
      {isLoading ? (
        <Spinner />
      ) : !data?.length ? (
        <p className="text-sm text-slate-500">Aucune demande en attente.</p>
      ) : (
        <ul className="divide-y divide-slate-100 text-sm">
          {data.map((r) => (
            <li key={r.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
              <div>
                <Link to={`/employes/${r.employee.id}`} className="font-medium text-brand-700 hover:underline">
                  {r.employee.full_name}
                </Link>
                <span className="text-slate-600"> — {r.type_label}</span>
                {r.comment && <div className="text-xs text-slate-500">« {r.comment} »</div>}
                <div className="text-xs text-slate-400">Demandé le {formatDateTime(r.created_at)}</div>
              </div>
              <div className="flex gap-2">
                {r.type !== 'autre' ? (
                  <Button className="px-3 py-1.5" loading={fulfill.isPending && fulfill.variables?.request.id === r.id} onClick={() => fulfill.mutate({ request: r })}>
                    <Sparkles className="h-4 w-4" /> Générer et envoyer
                  </Button>
                ) : (
                  <Button
                    className="px-3 py-1.5"
                    loading={fulfill.isPending && fulfill.variables?.request.id === r.id}
                    onClick={() => {
                      setTarget(r)
                      fileInput.current?.click()
                    }}
                  >
                    <Upload className="h-4 w-4" /> Déposer le document
                  </Button>
                )}
                <Button variant="secondary" className="px-3 py-1.5" onClick={() => setRefusing(r)}>
                  <X className="h-4 w-4 text-rose-600" /> Refuser
                </Button>
              </div>
            </li>
          ))}
        </ul>
      )}
      {refusing && (
        <Modal open title={`Refuser la demande de ${refusing.employee.full_name}`} onClose={() => setRefusing(null)}>
          <form
            className="space-y-4"
            onSubmit={(e) => {
              e.preventDefault()
              refuse.mutate()
            }}
          >
            <Field label="Motif" required error={validationErrors(refuse.error).reason?.[0]}>
              <Input autoFocus value={reason} onChange={(e) => setReason(e.target.value)} />
            </Field>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="ghost" onClick={() => setRefusing(null)}>
                Retour
              </Button>
              <Button type="submit" variant="danger" loading={refuse.isPending}>
                Refuser
              </Button>
            </div>
          </form>
        </Modal>
      )}
    </Card>
  )
}

/** DOC-06 : pièces expirées ou qui expirent dans les 30 jours. */
export function ExpiringDocuments() {
  const { data, isLoading } = useQuery({
    queryKey: ['document-expiring'],
    queryFn: async () => (await api.get<EmployeeDocument[]>('/documents/expiring?days=30')).data,
  })

  return (
    <Card title="Pièces à renouveler (30 jours)">
      {isLoading ? (
        <Spinner />
      ) : !data?.length ? (
        <p className="text-sm text-slate-500">Aucune pièce n'expire dans les 30 prochains jours.</p>
      ) : (
        <ul className="divide-y divide-slate-100 text-sm">
          {data.map((d) => (
            <li key={d.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
              <span>
                <Link to={`/employes/${d.employee?.id}`} className="font-medium text-brand-700 hover:underline">
                  {d.employee?.full_name}
                </Link>
                <span className="text-slate-600"> — {d.category.name}</span>
              </span>
              <ExpiryBadge date={d.expires_at} />
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

function CompanyDocumentsAdmin() {
  const queryClient = useQueryClient()
  const [publishing, setPublishing] = useState(false)
  const [readersOf, setReadersOf] = useState<CompanyDocumentItem | null>(null)
  const { data, isLoading } = useQuery({
    queryKey: ['company-documents'],
    queryFn: async () => (await api.get<CompanyDocumentItem[]>('/company-documents')).data,
  })
  const remove = useMutation({
    mutationFn: (id: number) => api.delete(`/company-documents/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['company-documents'] }),
  })

  return (
    <Card
      title="Documents de l'entreprise"
      actions={
        <Button variant="secondary" onClick={() => setPublishing(true)}>
          <Plus className="h-4 w-4" /> Publier
        </Button>
      }
    >
      {isLoading ? (
        <Spinner />
      ) : !data?.length ? (
        <p className="text-sm text-slate-500">Aucun document publié (règlement intérieur, charte informatique, notes de service…).</p>
      ) : (
        <ul className="divide-y divide-slate-100 text-sm">
          {data.map((d) => {
            const ratio = d.active_users_count ? (d.readers_count ?? 0) / d.active_users_count : 0
            return (
              <li key={d.id} className="flex flex-wrap items-center gap-3 py-3">
                <FileIcon mime={d.mime_type} />
                <div className="min-w-0 flex-1">
                  <a href={d.url} target="_blank" rel="noreferrer" className="font-medium text-brand-700 hover:underline">
                    {d.title}
                  </a>
                  <div className="text-xs text-slate-400">Publié le {formatDate(d.created_at.slice(0, 10))}</div>
                </div>
                {d.requires_acknowledgment ? (
                  <button onClick={() => setReadersOf(d)} className="flex items-center gap-2 text-xs text-slate-600 hover:text-brand-700" title="Voir qui a lu">
                    <Users className="h-4 w-4" />
                    <span className="h-1.5 w-20 rounded-full bg-slate-100">
                      <span className="block h-1.5 rounded-full bg-emerald-500" style={{ width: `${ratio * 100}%` }} />
                    </span>
                    {d.readers_count}/{d.active_users_count} lu
                  </button>
                ) : (
                  <Badge className="bg-slate-100 text-slate-600">Information</Badge>
                )}
                <button
                  className="p-1 text-slate-400 hover:text-rose-600"
                  aria-label="Supprimer"
                  onClick={() => confirm(`Retirer « ${d.title} » ?`) && remove.mutate(d.id)}
                >
                  <Trash2 className="h-4 w-4" />
                </button>
              </li>
            )
          })}
        </ul>
      )}
      {publishing && <PublishModal onClose={() => setPublishing(false)} />}
      {readersOf && <ReadersModal document={readersOf} onClose={() => setReadersOf(null)} />}
    </Card>
  )
}

function PublishModal({ onClose }: { onClose: () => void }) {
  const queryClient = useQueryClient()
  const [form, setForm] = useState({ title: '', description: '', requires_acknowledgment: true })
  const [file, setFile] = useState<File | null>(null)
  const save = useMutation({
    mutationFn: () => {
      const data = new FormData()
      data.append('title', form.title)
      if (form.description) data.append('description', form.description)
      data.append('requires_acknowledgment', form.requires_acknowledgment ? '1' : '0')
      if (file) data.append('file', file)
      return api.post('/company-documents', data)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['company-documents'] })
      onClose()
    },
  })
  const errors = validationErrors(save.error)

  return (
    <Modal open title="Publier un document de l'entreprise" onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        <Field label="Titre" required error={errors.title?.[0]}>
          <Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} placeholder="ex. Charte informatique" />
        </Field>
        <Field label="Description" error={errors.description?.[0]}>
          <Input value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
        </Field>
        <Field label="Fichier (PDF, image, Word — 10 Mo max.)" required error={errors.file?.[0]}>
          <input type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" className="text-sm" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
        </Field>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={form.requires_acknowledgment} onChange={(e) => setForm({ ...form, requires_acknowledgment: e.target.checked })} />
          Demander à chacun de confirmer sa lecture
        </label>
        <p className="text-xs text-slate-500">Tout le personnel sera prévenu par notification.</p>
        {save.isError && Object.keys(errors).length === 0 && <Alert>{errorMessage(save.error)}</Alert>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>
            Annuler
          </Button>
          <Button type="submit" loading={save.isPending} disabled={!file || !form.title}>
            Publier
          </Button>
        </div>
      </form>
    </Modal>
  )
}

function ReadersModal({ document, onClose }: { document: CompanyDocumentItem; onClose: () => void }) {
  const { data, isLoading } = useQuery({
    queryKey: ['company-documents', document.id, 'readers'],
    queryFn: async () => (await api.get<{ id: number; name: string; email: string; acknowledged_at: string | null }[]>(`/company-documents/${document.id}/readers`)).data,
  })
  const notRead = data?.filter((u) => !u.acknowledged_at) ?? []
  const read = data?.filter((u) => u.acknowledged_at) ?? []

  return (
    <Modal open title={`Lectures — ${document.title}`} onClose={onClose}>
      {isLoading ? (
        <Spinner />
      ) : (
        <div className="max-h-[60vh] space-y-4 overflow-y-auto text-sm">
          <div>
            <h3 className="mb-1 font-medium text-rose-700">Pas encore lu ({notRead.length})</h3>
            <ul className="text-slate-600">
              {notRead.map((u) => (
                <li key={u.id}>
                  {u.name} <span className="text-xs text-slate-400">{u.email}</span>
                </li>
              ))}
            </ul>
          </div>
          <div>
            <h3 className="mb-1 font-medium text-emerald-700">Lu ({read.length})</h3>
            <ul className="text-slate-600">
              {read.map((u) => (
                <li key={u.id}>
                  {u.name} <span className="text-xs text-slate-400">le {formatDateTime(u.acknowledged_at!)}</span>
                </li>
              ))}
            </ul>
          </div>
        </div>
      )}
    </Modal>
  )
}

function Categories() {
  const queryClient = useQueryClient()
  const { data, isLoading } = useDocumentCategories()
  const [editing, setEditing] = useState<Partial<DocumentCategory> | null>(null)
  const save = useMutation({
    mutationFn: (c: Partial<DocumentCategory>) => (c.id ? api.put(`/document-categories/${c.id}`, c) : api.post('/document-categories', c)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['document-categories'] })
      setEditing(null)
    },
  })
  const remove = useMutation({
    mutationFn: (id: number) => api.delete(`/document-categories/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['document-categories'] }),
  })
  const errors = validationErrors(save.error)

  return (
    <Card
      title="Catégories du coffre-fort"
      actions={
        <Button variant="secondary" onClick={() => setEditing({ name: '', code: '', visible_to_employee: true, visible_to_manager: false, has_expiry: false })}>
          <Plus className="h-4 w-4" /> Ajouter
        </Button>
      }
    >
      {remove.isError && (
        <div className="mb-3">
          <Alert>{errorMessage(remove.error)}</Alert>
        </div>
      )}
      {isLoading ? (
        <Spinner />
      ) : (
        <table className="min-w-full text-sm">
          <thead className="text-left text-xs text-slate-500 uppercase">
            <tr>
              <th className="pb-2">Catégorie</th>
              <th className="pb-2">Visible par</th>
              <th className="pb-2">Documents</th>
              <th />
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {data?.map((c) => (
              <tr key={c.id}>
                <td className="py-2">
                  {c.name} {c.has_expiry && <Badge className="ml-1 bg-slate-100 text-slate-600">Date d'expiration</Badge>}
                </td>
                <td className="py-2 text-slate-600">RH{c.visible_to_employee && ', employé'}{c.visible_to_manager && ', manager'}</td>
                <td className="py-2">{c.documents_count}</td>
                <td className="py-2 text-right whitespace-nowrap">
                  <button className="p-1 text-slate-400 hover:text-brand-700" aria-label="Modifier" onClick={() => setEditing(c)}>
                    <Pencil className="h-4 w-4" />
                  </button>
                  <button className="p-1 text-slate-400 hover:text-rose-600" aria-label="Supprimer" onClick={() => confirm(`Supprimer la catégorie « ${c.name} » ?`) && remove.mutate(c.id)}>
                    <Trash2 className="h-4 w-4" />
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {editing && (
        <Modal open title={editing.id ? 'Modifier la catégorie' : 'Nouvelle catégorie'} onClose={() => setEditing(null)}>
          <form
            className="space-y-4"
            onSubmit={(e) => {
              e.preventDefault()
              save.mutate(editing)
            }}
          >
            <div className="grid grid-cols-[1fr_8rem] gap-3">
              <Field label="Nom" required error={errors.name?.[0]}>
                <Input value={editing.name ?? ''} onChange={(e) => setEditing({ ...editing, name: e.target.value })} />
              </Field>
              <Field label="Code" required error={errors.code?.[0]}>
                <Input value={editing.code ?? ''} onChange={(e) => setEditing({ ...editing, code: e.target.value.toUpperCase() })} />
              </Field>
            </div>
            {(['visible_to_employee', 'visible_to_manager', 'has_expiry'] as const).map((key) => (
              <label key={key} className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={!!editing[key]} onChange={(e) => setEditing({ ...editing, [key]: e.target.checked })} />
                {{ visible_to_employee: "Visible par l'employé concerné", visible_to_manager: 'Visible par son manager', has_expiry: "Pièce avec date d'expiration" }[key]}
              </label>
            ))}
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

/** Page RH : demandes, pièces à renouveler, documents de l'entreprise, catégories. */
export default function DocumentsAdminPage() {
  return (
    <>
      <PageHeader title="Gestion des documents" subtitle="Demandes d'attestation, pièces à renouveler, documents de l'entreprise" />
      <div className="space-y-6">
        <div className="grid gap-6 xl:grid-cols-2">
          <PendingRequests />
          <ExpiringDocuments />
        </div>
        <CompanyDocumentsAdmin />
        <Categories />
      </div>
    </>
  )
}
