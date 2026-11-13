import type { ContractType, EmployeeStatus, LeaveStatus, PresenceStatus, Role } from '../api/types'

export const statusLabels: Record<EmployeeStatus, string> = {
  actif: 'Actif',
  periode_essai: "Période d'essai",
  suspendu: 'Suspendu',
  sorti: 'Sorti',
}

export const statusColors: Record<EmployeeStatus, string> = {
  actif: 'bg-emerald-100 text-emerald-800',
  periode_essai: 'bg-amber-100 text-amber-800',
  suspendu: 'bg-slate-200 text-slate-700',
  sorti: 'bg-rose-100 text-rose-800',
}

export const roleLabels: Record<Role, string> = {
  'super-admin': 'Administrateur',
  rh: 'Ressources humaines',
  manager: 'Manager',
  employe: 'Employé',
  direction: 'Direction',
}

export const maritalLabels: Record<string, string> = {
  celibataire: 'Célibataire',
  marie: 'Marié(e)',
  pacse: 'Pacsé(e)',
  divorce: 'Divorcé(e)',
  veuf: 'Veuf / veuve',
}

/** Libellés des champs, utilisés dans l'historique des modifications. */
export const fieldLabels: Record<string, string> = {
  matricule: 'Matricule',
  civility: 'Civilité',
  last_name: 'Nom',
  first_name: 'Prénom',
  birth_date: 'Date de naissance',
  birth_place: 'Lieu de naissance',
  nationality: 'Nationalité',
  marital_status: 'Situation familiale',
  children_count: "Nombre d'enfants",
  personal_email: 'E-mail personnel',
  work_email: 'E-mail professionnel',
  phone: 'Téléphone',
  address: 'Adresse',
  city: 'Ville',
  postal_code: 'Code postal',
  emergency_contact_name: "Contact d'urgence",
  emergency_contact_phone: "Téléphone d'urgence",
  department_id: 'Département',
  position_id: 'Poste',
  site_id: 'Site',
  manager_id: 'Manager',
  hire_date: "Date d'embauche",
  status: 'Statut',
  exit_date: 'Date de sortie',
  exit_reason: 'Motif de sortie',
  user_id: 'Compte utilisateur',
  photo_path: 'Photo',
}

/** Année en cours (au chargement de l'application). */
export const THIS_YEAR = new Date().getFullYear()

/** « 2026-10-12 » est lu comme une date locale (et non UTC, qui décalerait d'un jour selon le fuseau). */
export function parseDate(value: string): Date {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  return m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : new Date(value)
}

/** Date locale au format AAAA-MM-JJ. */
export function toIsoDate(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

export function formatDate(value: string | null | undefined): string {
  if (!value) return '—'
  return parseDate(value).toLocaleDateString('fr-FR')
}

export function formatDateTime(value: string): string {
  return new Date(value).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })
}

export const leaveStatusLabels: Record<LeaveStatus, string> = {
  en_attente: 'En attente',
  valide_manager: 'Validée manager',
  approuve: 'Approuvée',
  refuse: 'Refusée',
  annule: 'Annulée',
}

export const leaveStatusColors: Record<LeaveStatus, string> = {
  en_attente: 'bg-amber-100 text-amber-800',
  valide_manager: 'bg-sky-100 text-sky-800',
  approuve: 'bg-emerald-100 text-emerald-800',
  refuse: 'bg-rose-100 text-rose-800',
  annule: 'bg-slate-200 text-slate-600',
}

/** « 1 j », « 2,5 j » */
export function formatDays(days: number): string {
  return `${days.toLocaleString('fr-FR', { maximumFractionDigits: 2 })} j`
}

/** Période lisible : « 12/10/2026 → 16/10/2026 (après-midi → midi) » */
export function formatPeriod(l: { start_date: string; end_date: string; start_half_day: boolean; end_half_day: boolean }): string {
  const start = formatDate(l.start_date) + (l.start_half_day ? ' (après-midi)' : '')
  if (l.start_date === l.end_date) {
    return l.end_half_day ? `${formatDate(l.start_date)} (matin)` : start
  }
  return `${start} → ${formatDate(l.end_date)}${l.end_half_day ? ' (matin)' : ''}`
}

export const contractTypeLabels: Record<ContractType, string> = {
  CDI: 'CDI',
  CDD: 'CDD',
  stage: 'Stage',
  alternance: 'Alternance',
  interim: 'Intérim',
  freelance: 'Freelance',
}

export const contractStatusLabels = { a_venir: 'À venir', en_cours: 'En cours', termine: 'Terminé' } as const
export const contractStatusColors = {
  a_venir: 'bg-sky-100 text-sky-800',
  en_cours: 'bg-emerald-100 text-emerald-800',
  termine: 'bg-slate-200 text-slate-600',
} as const

export const endReasonLabels: Record<string, string> = {
  demission: 'Démission',
  licenciement: 'Licenciement',
  fin_cdd: 'Fin de CDD',
  rupture_conventionnelle: 'Rupture conventionnelle',
  retraite: 'Départ à la retraite',
  fin_periode_essai: "Rupture de la période d'essai",
  fin_stage: 'Fin de stage',
  autre: 'Autre',
}

export function formatMoney(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—'
  return value.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 })
}

/** « 245 Ko », « 1,2 Mo » */
export function formatSize(bytes: number): string {
  if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} Ko`
  return `${(bytes / 1024 / 1024).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} Mo`
}

/** Nombre de jours avant une date (négatif si dépassée). */
export function daysUntil(date: string): number {
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return Math.round((parseDate(date).getTime() - today.getTime()) / 86_400_000)
}

/** 450 → « 7 h 30 », 45 → « 45 min » */
export function formatMinutes(minutes: number): string {
  const sign = minutes < 0 ? '-' : ''
  const m = Math.abs(minutes)
  if (m < 60) return `${sign}${m} min`
  return `${sign}${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')}`
}

export const WEEKDAYS_SHORT = ['', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.']
export const WEEKDAYS_LONG = ['', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche']

export const presenceLabels: Record<PresenceStatus, { label: string; className: string }> = {
  present: { label: 'Présent', className: 'bg-emerald-100 text-emerald-800' },
  parti: { label: 'Parti', className: 'bg-slate-200 text-slate-700' },
  conge: { label: 'En congé', className: 'bg-sky-100 text-sky-800' },
  repos: { label: 'Repos', className: 'bg-slate-100 text-slate-500' },
  non_arrive: { label: 'Pas encore arrivé', className: 'bg-rose-100 text-rose-800' },
  attendu: { label: 'Attendu', className: 'bg-amber-100 text-amber-800' },
}

export const anomalyLabels = {
  absence: { label: 'Absence', className: 'bg-rose-100 text-rose-800' },
  retard: { label: 'Retard', className: 'bg-amber-100 text-amber-800' },
  sortie_manquante: { label: 'Départ non pointé', className: 'bg-rose-100 text-rose-800' },
}
