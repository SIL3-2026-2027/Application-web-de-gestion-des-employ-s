import { useQuery } from '@tanstack/react-query'
import { api } from './client'
import type { Department, DocumentCategory, WorkSchedule, LeaveBalances, LeaveType, Option, Position, Site } from './types'

/** Référentiels utilisés dans les filtres et formulaires. */
export const useDepartments = () =>
  useQuery({ queryKey: ['departments'], queryFn: async () => (await api.get<Department[]>('/departments')).data })

export const usePositions = () =>
  useQuery({ queryKey: ['positions'], queryFn: async () => (await api.get<Position[]>('/positions')).data })

export const useSites = () => useQuery({ queryKey: ['sites'], queryFn: async () => (await api.get<Site[]>('/sites')).data })

export const useEmployeeOptions = () =>
  useQuery({ queryKey: ['employees', 'options'], queryFn: async () => (await api.get<Option[]>('/employees/options')).data })

export const useLeaveTypes = (all = false) =>
  useQuery({
    queryKey: ['leave-types', all],
    queryFn: async () => (await api.get<LeaveType[]>(`/leave-types${all ? '?all=1' : ''}`)).data,
  })

export const useLeaveBalances = (employeeId: number | null | undefined, year: number) =>
  useQuery({
    queryKey: ['leave-balances', employeeId, year],
    queryFn: async () => (await api.get<LeaveBalances>(`/employees/${employeeId}/leave-balances?year=${year}`)).data,
    enabled: !!employeeId,
  })

export const useDocumentCategories = () =>
  useQuery({ queryKey: ['document-categories'], queryFn: async () => (await api.get<DocumentCategory[]>('/document-categories')).data })

export const useWorkSchedules = () =>
  useQuery({ queryKey: ['attendance', 'schedules'], queryFn: async () => (await api.get<WorkSchedule[]>('/work-schedules')).data })
