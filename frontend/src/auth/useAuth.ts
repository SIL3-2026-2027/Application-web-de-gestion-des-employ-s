import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AxiosError } from 'axios'
import { api } from '../api/client'
import type { Role, User } from '../api/types'

const ME_KEY = ['me']

async function fetchMe(): Promise<User | null> {
  try {
    const { data } = await api.get<{ data: User }>('/me')
    return data.data
  } catch (e) {
    if (e instanceof AxiosError && e.response?.status === 401) return null
    throw e
  }
}

export function useAuth() {
  const queryClient = useQueryClient()
  const { data: user, isLoading } = useQuery({ queryKey: ME_KEY, queryFn: fetchMe, staleTime: 5 * 60_000 })

  const login = useMutation({
    mutationFn: async (credentials: { email: string; password: string; remember?: boolean }) => {
      const { data } = await api.post<{ data: User }>('/login', credentials)
      return data.data
    },
    onSuccess: (u) => queryClient.setQueryData(ME_KEY, u),
  })

  const logout = useMutation({
    mutationFn: () => api.post('/logout'),
    onSettled: () => {
      queryClient.clear()
      queryClient.setQueryData(ME_KEY, null)
    },
  })

  const hasRole = (...roles: Role[]) => !!user && user.roles.some((r) => roles.includes(r))

  return {
    user: user ?? null,
    isLoading,
    login,
    logout,
    hasRole,
    isHr: hasRole('super-admin', 'rh'),
  }
}
