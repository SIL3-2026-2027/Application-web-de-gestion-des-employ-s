import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, Send } from 'lucide-react'
import { useState } from 'react'
import { api, errorMessage, validationErrors } from '../../api/client'
import type { CompanyDocumentItem, DocumentRequestItem, DocumentRequestType } from '../../api/types'
import { useAuth } from '../../auth/useAuth'
import { EmployeeDocuments, FileIcon } from '../../components/documents'
import { Alert, Badge, Button, Card, Field, Input, PageHeader, Select, Spinner } from '../../components/ui'
import { formatDate, formatDateTime } from '../../lib/labels'

const requestStatus = {
  en_attente: { label: 'En cours', className: 'bg-amber-100 text-amber-800' },
  traitee: { label: 'Disponible', className: 'bg-emerald-100 text-emerald-800' },
  refusee: { label: 'Refusée', className: 'bg-rose-100 text-rose-800' },
} as const

/** DOC-05 : documents de l'entreprise, avec confirmation de lecture. */
export function CompanyDocuments() {
  const queryClient = useQueryClient()
  const { data, isLoading } = useQuery({
    queryKey: ['company-documents'],
    queryFn: async () => (await api.get<CompanyDocumentItem[]>('/company-documents')).data,
  })
  const acknowledge = useMutation({
    mutationFn: (id: number) => api.post(`/company-documents/${id}/acknowledge`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['company-documents'] }),
  })

  if (isLoading) return <Spinner />
  if (!data?.length) return <p className="text-sm text-slate-500">Aucun document publié.</p>

  return (
    <ul className="divide-y divide-slate-100">
      {data.map((d) => (
        <li key={d.id} className="flex flex-wrap items-center gap-3 py-3">
          <FileIcon mime={d.mime_type} />
          <div className="min-w-0 flex-1">
            <a href={d.url} target="_blank" rel="noreferrer" className="font-medium text-brand-700 hover:underline">
              {d.title}
            </a>
            {d.description && <div className="text-xs text-slate-500">{d.description}</div>}
            <div className="text-xs text-slate-400">Publié le {formatDate(d.created_at.slice(0, 10))}</div>
          </div>
          {d.requires_acknowledgment &&
            (d.acknowledged_at ? (
              <span className="flex items-center gap-1 text-xs text-emerald-700">
                <CheckCircle2 className="h-4 w-4" /> Lu le {formatDateTime(d.acknowledged_at)}
              </span>
            ) : (
              <Button variant="secondary" className="px-3 py-1.5" loading={acknowledge.isPending && acknowledge.variables === d.id} onClick={() => acknowledge.mutate(d.id)}>
                J'ai lu et pris connaissance
              </Button>
            ))}
        </li>
      ))}
    </ul>
  )
}

function RequestForm() {
  const queryClient = useQueryClient()
  const [type, setType] = useState<DocumentRequestType>('attestation_travail')
  const [comment, setComment] = useState('')
  const send = useMutation({
    mutationFn: () => api.post('/document-requests', { type, comment: comment || null }),
    onSuccess: () => {
      setComment('')
      queryClient.invalidateQueries({ queryKey: ['document-requests'] })
    },
  })
  const errors = validationErrors(send.error)

  return (
    <form
      className="grid gap-3 sm:grid-cols-[14rem_1fr_auto] sm:items-start"
      onSubmit={(e) => {
        e.preventDefault()
        send.mutate()
      }}
    >
      <Field label="Document" error={errors.type?.[0]}>
        <Select value={type} onChange={(e) => setType(e.target.value as DocumentRequestType)}>
          <option value="attestation_travail">Attestation de travail</option>
          <option value="attestation_salaire">Attestation de salaire</option>
          <option value="autre">Autre document</option>
        </Select>
      </Field>
      <Field label={type === 'autre' ? 'Document souhaité' : 'Précision (facultatif)'} error={errors.comment?.[0]}>
        <Input value={comment} onChange={(e) => setComment(e.target.value)} placeholder={type === 'autre' ? 'ex. attestation pour la CAF' : ''} />
      </Field>
      <Button type="submit" className="sm:mt-6" loading={send.isPending}>
        <Send className="h-4 w-4" /> Demander
      </Button>
      {send.isSuccess && (
        <div className="sm:col-span-3">
          <Alert tone="success">Demande envoyée aux ressources humaines. Vous serez prévenu dès que le document sera disponible.</Alert>
        </div>
      )}
      {send.isError && Object.keys(errors).length === 0 && (
        <div className="sm:col-span-3">
          <Alert>{errorMessage(send.error)}</Alert>
        </div>
      )}
    </form>
  )
}

/** « Mes documents » : coffre-fort personnel, demandes d'attestation, documents de l'entreprise. */
export default function MyDocumentsPage() {
  const { user } = useAuth()
  const requests = useQuery({
    queryKey: ['document-requests', 'mine'],
    queryFn: async () => (await api.get<DocumentRequestItem[]>('/document-requests')).data,
    enabled: !!user?.employee_id,
  })

  return (
    <>
      <PageHeader title="Mes documents" />
      <div className="space-y-6">
        <Card title="Documents de l'entreprise">
          <CompanyDocuments />
        </Card>

        {user?.employee_id && (
          <>
            <Card title="Demander une attestation">
              <RequestForm />
              {!!requests.data?.length && (
                <ul className="mt-5 divide-y divide-slate-100 border-t border-slate-100 text-sm">
                  {requests.data.map((r) => (
                    <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                      <span>
                        {r.type_label}
                        {r.comment && <span className="text-slate-500"> — {r.comment}</span>}
                        <span className="ml-2 text-xs text-slate-400">demandé le {formatDate(r.created_at.slice(0, 10))}</span>
                      </span>
                      <span className="flex items-center gap-2">
                        {r.document && (
                          <a href={r.document.url} target="_blank" rel="noreferrer" className="text-brand-700 hover:underline">
                            Ouvrir
                          </a>
                        )}
                        {r.refusal_reason && <span className="text-xs text-rose-700">{r.refusal_reason}</span>}
                        <Badge className={requestStatus[r.status].className}>{requestStatus[r.status].label}</Badge>
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </Card>

            <div>
              <h2 className="mb-3 font-semibold text-slate-800">Mon dossier</h2>
              <EmployeeDocuments employee={{ id: user.employee_id, full_name: user.name, status: 'actif' }} />
            </div>
          </>
        )}
      </div>
    </>
  )
}
