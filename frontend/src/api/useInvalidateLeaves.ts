import { useQueryClient } from '@tanstack/react-query'

/** Rafraîchit toutes les vues qui dépendent des congés après une action. */
export function useInvalidateLeaves() {
  const queryClient = useQueryClient()
  return () => {
    for (const key of ['leave-requests', 'leave-balances', 'leave-calendar', 'leave-pending-count']) {
      queryClient.invalidateQueries({ queryKey: [key] })
    }
  }
}
