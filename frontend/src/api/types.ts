export type Role = 'super-admin' | 'rh' | 'manager' | 'employe' | 'direction'

export interface User {
  id: number
  name: string
  email: string
  is_active: boolean
  must_change_password: boolean
  roles: Role[]
  employee_id: number | null
  employee?: { id: number; full_name: string; matricule: string } | null
  last_login_at: string | null
  can_manage?: boolean
}

export type EmployeeStatus = 'actif' | 'periode_essai' | 'suspendu' | 'sorti'

export interface Employee {
  id: number
  matricule: string
  civility: 'M.' | 'Mme' | null
  last_name: string
  first_name: string
  full_name: string
  birth_date: string | null
  birth_place: string | null
  nationality: string | null
  marital_status: string | null
  children_count: number
  personal_email: string | null
  work_email: string | null
  phone: string | null
  address: string | null
  city: string | null
  postal_code: string | null
  emergency_contact_name: string | null
  emergency_contact_phone: string | null
  social_security_number?: string | null
  iban?: string | null
  department_id: number | null
  position_id: number | null
  site_id: number | null
  manager_id: number | null
  work_schedule_id: number | null
  department?: { id: number; name: string } | null
  position?: { id: number; title: string } | null
  site?: { id: number; name: string } | null
  manager?: { id: number; full_name: string } | null
  contract?: { id: number; type: ContractType; end_date: string | null } | null
  hire_date: string
  status: EmployeeStatus
  exit_date: string | null
  exit_reason: string | null
  has_account: boolean
  created_at: string
  updated_at: string
}

export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null }
}

export interface Department {
  id: number
  name: string
  code: string
  parent_id: number | null
  manager_id: number | null
  parent?: { id: number; name: string } | null
  manager?: { id: number; first_name: string; last_name: string } | null
  employees_count: number
}

export interface Position {
  id: number
  title: string
  category: string | null
  min_salary: string | null
  max_salary: string | null
  employees_count: number
}

export interface Site {
  id: number
  name: string
  address: string | null
  city: string | null
  country: string
  employees_count: number
}

export interface Option {
  id: number
  label: string
}

export interface Dashboard {
  headcount: number
  on_probation: number
  hires_this_month: number
  exits_this_month: number
  by_department: { name: string; count: number }[]
  recent_hires: { id: number; full_name: string; position: string | null; hire_date: string }[]
}

export interface HistoryEntry {
  id: number
  event: 'created' | 'updated' | 'deleted' | null
  description: string
  causer: string | null
  changes: { attributes?: Record<string, unknown>; old?: Record<string, unknown> }
  created_at: string
}

// ---------- Congés ----------

export interface LeaveType {
  id: number
  name: string
  code: string
  color: string
  is_deductible: boolean
  monthly_accrual: number | null
  annual_allowance: number | null
  requires_attachment: boolean
  requires_hr_approval: boolean
  employee_can_request: boolean
  is_active: boolean
}

export type LeaveStatus = 'en_attente' | 'valide_manager' | 'approuve' | 'refuse' | 'annule'

export interface LeaveRequest {
  id: number
  employee?: { id: number; full_name: string; matricule: string }
  leave_type?: { id: number; name: string; code: string; color: string }
  start_date: string
  end_date: string
  start_half_day: boolean
  end_half_day: boolean
  days: number
  reason: string | null
  has_attachment: boolean
  status: LeaveStatus
  refusal_reason: string | null
  created_by?: string | null
  manager_decision: { by?: string | null; at: string } | null
  hr_decision: { by?: string | null; at: string } | null
  created_at: string
  can: { decide: boolean; cancel: boolean }
}

export interface LeaveBalance {
  leave_type_id: number
  leave_type: string
  code: string
  color: string
  year: number
  accrued: number
  adjustments: number
  taken: number
  pending: number
  remaining: number
}

export interface LeaveBalances {
  year: number
  balances: LeaveBalance[]
  adjustments: { id: number; leave_type: string; days: number; reason: string; author: string | null; created_at: string }[]
}

export interface LeavePreview {
  days: number
  remaining: number | null
  errors: Record<string, string>
}

export interface CalendarData {
  month: string
  employees: { id: number; full_name: string }[]
  holidays: { date: string; name: string }[]
  leaves: {
    id: number
    employee_id: number
    start_date: string
    end_date: string
    start_half_day: boolean
    end_half_day: boolean
    status: LeaveStatus
    leave_type: { name: string; code: string; color: string }
  }[]
}

export interface PublicHoliday {
  id: number
  date: string
  name: string
}

// ---------- Contrats ----------

export type ContractType = 'CDI' | 'CDD' | 'stage' | 'alternance' | 'interim' | 'freelance'

export interface Contract {
  id: number
  employee_id: number
  reference: string
  type: ContractType
  status: 'a_venir' | 'en_cours' | 'termine'
  start_date: string
  end_date: string | null
  effective_end_date: string | null
  trial_end_date: string | null
  weekly_hours: number
  base_salary?: number
  position: { id: number; title: string } | null
  department: { id: number; name: string } | null
  collective_agreement: string | null
  description: string | null
  current_terms: { weekly_hours: number | null; base_salary?: number | null; position: string | null; department: string | null }
  ended_at: string | null
  end_reason: string | null
  end_reason_label: string | null
  end_notes: string | null
  has_document: boolean
  amendments: {
    id: number
    reference: string
    start_date: string
    applied: boolean
    description: string | null
    has_document: boolean
    changes: { end_date?: string; weekly_hours?: number; base_salary?: number; position?: string; department?: string }
  }[]
  can: { manage: boolean; documents: boolean }
}

export interface ContractAlert {
  kind: 'fin_contrat' | 'fin_periode_essai'
  date: string
  days_left: number
  contract_id: number
  reference: string
  type: ContractType
  employee: { id: number; full_name: string }
}

// ---------- Notifications ----------

export interface AppNotification {
  id: string
  category: 'leave_to_approve' | 'leave_decision' | 'contract_deadline' | 'documents' | 'attendance'
  title: string
  message: string
  url: string
  read: boolean
  created_at: string
}

export interface NotificationList {
  unread_count: number
  data: AppNotification[]
  meta: { current_page: number; last_page: number; total: number }
}

export interface NotificationPreference {
  key: string
  label: string
  email: boolean
}

// ---------- Documents ----------

export interface DocumentCategory {
  id: number
  name: string
  code: string
  visible_to_employee: boolean
  visible_to_manager: boolean
  has_expiry: boolean
  documents_count?: number
}

export interface EmployeeDocument {
  id: number
  title: string
  category: { id: number; name: string; has_expiry: boolean }
  original_name: string
  mime_type: string
  size: number
  expires_at: string | null
  source: 'depot' | 'genere'
  notes: string | null
  uploaded_by: string | null
  created_at: string
  url: string
  employee?: { id: number; full_name: string }
  days_left?: number
}

export type DocumentRequestType = 'attestation_travail' | 'attestation_salaire' | 'autre'

export interface DocumentRequestItem {
  id: number
  type: DocumentRequestType
  type_label: string
  comment: string | null
  status: 'en_attente' | 'traitee' | 'refusee'
  refusal_reason: string | null
  employee: { id: number; full_name: string; matricule: string }
  document: { id: number; title: string; url: string } | null
  handled_by: string | null
  handled_at: string | null
  created_at: string
}

export interface CompanyDocumentItem {
  id: number
  title: string
  description: string | null
  original_name: string
  mime_type: string
  size: number
  requires_acknowledgment: boolean
  acknowledged_at: string | null
  readers_count: number | null
  active_users_count: number | null
  created_at: string
  url: string
}

// ---------- Présences ----------

export type Anomaly = 'absence' | 'retard' | 'sortie_manquante'

export interface TimesheetDay {
  date: string
  weekday: number
  holiday: string | null
  planned: number
  planned_start: string | null
  planned_end: string | null
  segments: { id: number; in: string; out: string | null; source: 'application' | 'correction' | 'saisie_rh' }[]
  worked: number
  late: number
  overtime_detected: number
  overtime_approved: number
  overtime_pending: number
  leave: { name: string; color: string; half_day: boolean } | null
  anomalies: Anomaly[]
}

export interface Timesheet {
  employee: { id: number; full_name: string }
  schedule: { id: number; name: string; weekly_minutes: number } | null
  days: TimesheetDay[]
  totals: { planned: number; worked: number; overtime_approved: number; late_count: number; late_minutes: number; absences: number; missing_out: number }
}

export type PresenceStatus = 'present' | 'parti' | 'conge' | 'repos' | 'non_arrive' | 'attendu'

export interface TodayStatus extends TimesheetDay {
  status: PresenceStatus
  schedule: { name: string; late_tolerance: number } | null
  server_time: string
}

export interface TeamPresence {
  employee: { id: number; full_name: string; position: string | null }
  status: PresenceStatus
  segments: TimesheetDay['segments']
  worked: number
  planned: number
  planned_start: string | null
  late: number
  leave: TimesheetDay['leave']
}

export interface AttendanceRequest {
  id: number
  employee: { id: number; full_name: string }
  date: string
  reason: string
  status: 'en_attente' | 'approuve' | 'refuse'
  refusal_reason: string | null
  decided_by: string | null
  created_at: string
  can_decide: boolean
  minutes?: number
  segments?: { in: string; out: string }[]
}

export interface WorkSchedule {
  id: number
  name: string
  days: Record<string, { start: string; end: string; break: number }>
  late_tolerance: number
  is_default: boolean
  employees_count: number
  weekly_minutes: number
}

// ---------- Paie ----------

export interface PayrollRubric {
  id: number
  code: string
  label: string
  kind: 'gain' | 'retenue' | 'information'
  unit: 'montant' | 'heures' | 'jours'
  export_code: string | null
  is_active: boolean
  items_count?: number
}

export interface PayrollItem {
  id: number
  employee_id: number
  rubric: Pick<PayrollRubric, 'id' | 'code' | 'label' | 'kind' | 'unit'>
  quantity: number | null
  amount: number | null
  source: 'manuel' | 'recurrent' | 'heures_sup' | 'conge' | 'absence'
  comment: string | null
}

export interface PayrollPeriodDetail {
  month: string
  status: 'ouverte' | 'cloturee'
  computed_at: string | null
  closed_at: string | null
  closed_by: string | null
  employees: {
    id: number
    matricule: string
    full_name: string
    department: string | null
    contract_type: string | null
    monthly_salary: number | null
    entry: string | null
    exit: string | null
    items: PayrollItem[]
  }[]
}

export interface RecurringPayItem {
  id: number
  employee: { id: number; full_name: string; matricule: string }
  rubric: Pick<PayrollRubric, 'id' | 'code' | 'label' | 'unit'>
  amount: number
  start_month: string
  end_month: string | null
  comment: string | null
}
