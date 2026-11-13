import { useQuery } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { api } from '../../api/client'
import type { LeaveRequest, Paginated } from '../../api/types'
import { useAuth } from '../../auth/useAuth'
import { LeaveRequestList, LeaveRequestModal } from '../../components/leaves'
import { Button, Card, PageHeader, Select, Spinner } from '../../components/ui'
import { leaveStatusLabels } from '../../lib/labels'

export default function ApprovalsPage() {
  const { isHr } = useAuth()
  const [declaring, setDeclaring] = useState(false)
  const [status, setStatus] = useState('')

  const pending = useQuery({
    queryKey: ['leave-requests', 'to_approve'],
    queryFn: async () => (await api.get<Paginated<LeaveRequest>>('/leave-requests?scope=to_approve')).data,
  })

  // Historique de toutes les demandes du périmètre (équipe ou entreprise)
  const all = useQuery({
    queryKey: ['leave-requests', 'all', status],
    queryFn: async () => (await api.get<Paginated<LeaveRequest>>(`/leave-requests?scope=all${status ? `&status=${status}` : ''}`)).data,
  })

  return (
    <>
      <PageHeader
        title="Validation des congés"
        subtitle={isHr ? 'Demandes de toute l’entreprise' : 'Demandes de votre équipe'}
        actions={
          isHr && (
            <Button onClick={() => setDeclaring(true)}>
              <Plus className="h-4 w-4" /> Déclarer une absence
            </Button>
          )
        }
      />

      <Card title={`À valider${pending.data ? ` (${pending.data.meta.total})` : ''}`}>
        {pending.isLoading ? <Spinner /> : <LeaveRequestList requests={pending.data?.data ?? []} showEmployee emptyText="Aucune demande en attente de votre validation." />}
      </Card>

      <Card
        title="Toutes les demandes"
        className="mt-6"
        actions={
          <Select value={status} onChange={(e) => setStatus(e.target.value)} className="w-48">
            <option value="">Tous les statuts</option>
            {Object.entries(leaveStatusLabels).map(([v, l]) => (
              <option key={v} value={v}>
                {l}
              </option>
            ))}
          </Select>
        }
      >
        {all.isLoading ? <Spinner /> : <LeaveRequestList requests={all.data?.data ?? []} showEmployee emptyText="Aucune demande." />}
      </Card>

      {declaring && <LeaveRequestModal open onClose={() => setDeclaring(false)} />}
    </>
  )
}
