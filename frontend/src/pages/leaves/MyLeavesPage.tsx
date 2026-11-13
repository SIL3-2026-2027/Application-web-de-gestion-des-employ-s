import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { api } from '../../api/client'
import { useLeaveBalances } from '../../api/queries'
import type { LeaveRequest, Paginated } from '../../api/types'
import { THIS_YEAR } from '../../lib/labels'
import { useAuth } from '../../auth/useAuth'
import { BalanceCards, LeaveRequestList, LeaveRequestModal } from '../../components/leaves'
import { Alert, Button, Card, PageHeader, Select, Spinner } from '../../components/ui'

export default function MyLeavesPage() {
  const { user } = useAuth()
  const [year, setYear] = useState(THIS_YEAR)
  const [creating, setCreating] = useState(false)

  const balances = useLeaveBalances(user?.employee_id, year)
  const requests = useQuery({
    queryKey: ['leave-requests', 'mine', year],
    queryFn: async () => (await api.get<Paginated<LeaveRequest>>(`/leave-requests?scope=mine&year=${year}`)).data,
    placeholderData: keepPreviousData,
  })

  if (!user?.employee_id) {
    return <Alert>Votre compte n'est rattaché à aucun dossier employé : vous ne pouvez pas poser de congés.</Alert>
  }

  return (
    <>
      <PageHeader
        title="Mes congés"
        actions={
          <>
            <Select value={year} onChange={(e) => setYear(Number(e.target.value))} className="w-28">
              {[THIS_YEAR - 1, THIS_YEAR, THIS_YEAR + 1].map((y) => (
                <option key={y}>{y}</option>
              ))}
            </Select>
            <Button onClick={() => setCreating(true)}>
              <Plus className="h-4 w-4" /> Nouvelle demande
            </Button>
          </>
        }
      />

      {balances.isLoading ? <Spinner /> : <BalanceCards balances={balances.data?.balances ?? []} />}

      <Card title={`Mes demandes ${year}`} className="mt-6">
        {requests.isLoading ? <Spinner /> : <LeaveRequestList requests={requests.data?.data ?? []} emptyText="Aucune demande pour cette année." />}
      </Card>

      {creating && <LeaveRequestModal open onClose={() => setCreating(false)} employeeId={user.employee_id} />}
    </>
  )
}
