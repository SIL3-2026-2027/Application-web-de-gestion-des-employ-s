import { useQueryClient } from '@tanstack/react-query'

/** Rafraîchit les écrans de présence après une action. */
export function useRefreshAttendance() {
  const queryClient = useQueryClient()
  return () => {
    for (const key of ['attendance', 'overtime-requests', 'attendance-corrections']) {
      queryClient.invalidateQueries({ queryKey: [key] })
    }
  }
}
