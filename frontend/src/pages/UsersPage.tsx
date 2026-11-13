import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { KeyRound, Pencil, Plus, Power, Search, UserPlus } from 'lucide-react'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { api, errorMessage, validationErrors } from '../api/client'
import type { Paginated, Role, User } from '../api/types'
import { useAuth } from '../auth/useAuth'
import PasswordFields from '../components/PasswordFields'
import { isStrongPassword } from '../lib/password'
import { Alert, Badge, Button, Field, Input, Modal, PageHeader, Select, Spinner } from '../components/ui'
import { formatDateTime, roleLabels } from '../lib/labels'

type UsersResponse = Paginated<User> & { assignable_roles: Role[] }
interface EmployeeOption {
  id: number
  label: string
  work_email: string | null
}

function useInvalidateUsers() {
  const queryClient = useQueryClient()
  return () => {
    queryClient.invalidateQueries({ queryKey: ['users'] })
    queryClient.invalidateQueries({ queryKey: ['employees'] })
    queryClient.invalidateQueries({ queryKey: ['employee'] })
  }
}

function RoleCheckboxes({ value, onChange, assignable, error }: { value: Role[]; onChange: (roles: Role[]) => void; assignable: Role[]; error?: string }) {
  return (
    <Field label="Rôles" required error={error}>
      <div className="flex flex-wrap gap-x-5 gap-y-2 pt-1">
        {assignable.map((r) => (
          <label key={r} className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={value.includes(r)} onChange={(e) => onChange(e.target.checked ? [...value, r] : value.filter((x) => x !== r))} />
            {roleLabels[r]}
          </label>
        ))}
      </div>
    </Field>
  )
}

/** Inscription : l'administration saisit l'identifiant et le mot de passe (aucun e-mail envoyé). */
function CreateModal({ assignable, preselectedEmployee, onClose }: { assignable: Role[]; preselectedEmployee?: number; onClose: () => void }) {
  const invalidate = useInvalidateUsers()
  const { data: employees } = useQuery({
    queryKey: ['users', 'employees-without-account'],
    queryFn: async () => (await api.get<EmployeeOption[]>('/users/employees-without-account')).data,
  })

  const [form, setForm] = useState({
    employee_id: preselectedEmployee ? String(preselectedEmployee) : '',
    name: '',
    email: '',
    roles: ['employe'] as Role[],
    password: '',
    password_confirmation: '',
    must_change_password: true,
  })
  const set = (patch: Partial<typeof form>) => setForm((f) => ({ ...f, ...patch }))

  // L'e-mail professionnel du dossier sert d'identifiant par défaut
  const pickEmployee = (id: string) => {
    const emp = employees?.find((e) => String(e.id) === id)
    set({ employee_id: id, ...(emp?.work_email ? { email: emp.work_email } : {}) })
  }
  const preselected = employees?.find((e) => e.id === preselectedEmployee)
  const email = form.email || (form.employee_id === String(preselectedEmployee) ? (preselected?.work_email ?? '') : '')

  const save = useMutation({
    mutationFn: () => api.post('/users', { ...form, email, employee_id: form.employee_id || null, name: form.employee_id ? null : form.name }),
    onSuccess: () => {
      invalidate()
      onClose()
    },
  })
  const errors = validationErrors(save.error)
  const ready = email && form.roles.length && isStrongPassword(form.password) && form.password === form.password_confirmation

  return (
    <Modal open title="Inscrire un utilisateur" onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        <Field label="Dossier employé" error={errors.employee_id?.[0]}>
          <Select value={form.employee_id} onChange={(e) => pickEmployee(e.target.value)}>
            <option value="">Aucun (compte sans dossier, ex. administrateur)</option>
            {employees?.map((e) => (
              <option key={e.id} value={e.id}>
                {e.label}
              </option>
            ))}
          </Select>
        </Field>
        {!form.employee_id && (
          <Field label="Nom affiché" required error={errors.name?.[0]}>
            <Input value={form.name} onChange={(e) => set({ name: e.target.value })} />
          </Field>
        )}
        <Field label="E-mail (identifiant de connexion)" required error={errors.email?.[0]}>
          <Input type="email" value={email} onChange={(e) => set({ email: e.target.value })} />
        </Field>
        <RoleCheckboxes value={form.roles} onChange={(roles) => set({ roles })} assignable={assignable} error={errors.roles?.[0]} />
        <PasswordFields
          password={form.password}
          confirmation={form.password_confirmation}
          onChange={(password, password_confirmation) => set({ password, password_confirmation })}
          error={errors.password?.[0]}
          allowGenerate
        />
        <label className="flex items-start gap-2 text-sm">
          <input type="checkbox" className="mt-1" checked={form.must_change_password} onChange={(e) => set({ must_change_password: e.target.checked })} />
          <span>
            Demander de changer ce mot de passe à la première connexion
            <span className="block text-xs text-slate-500">Recommandé : ainsi, personne d'autre que l'employé ne connaît son mot de passe.</span>
          </span>
        </label>
        {save.isError && Object.keys(errors).length === 0 && <Alert>{errorMessage(save.error)}</Alert>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>
            Annuler
          </Button>
          <Button type="submit" loading={save.isPending} disabled={!ready}>
            Créer le compte
          </Button>
        </div>
      </form>
    </Modal>
  )
}

function EditModal({ user, assignable, onClose }: { user: User; assignable: Role[]; onClose: () => void }) {
  const invalidate = useInvalidateUsers()
  const [email, setEmail] = useState(user.email)
  const [roles, setRoles] = useState<Role[]>(user.roles)
  const save = useMutation({
    mutationFn: () => api.put(`/users/${user.id}`, { email, roles }),
    onSuccess: () => {
      invalidate()
      onClose()
    },
  })
  const errors = validationErrors(save.error)
  // Un rôle que l'on ne peut pas attribuer reste affiché, non modifiable
  const fixed = user.roles.filter((r) => !assignable.includes(r))

  return (
    <Modal open title={`Modifier le compte de ${user.name}`} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        <Field label="E-mail (identifiant de connexion)" required error={errors.email?.[0]}>
          <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
        </Field>
        <RoleCheckboxes value={roles} onChange={(r) => setRoles([...fixed, ...r.filter((x) => !fixed.includes(x))])} assignable={assignable} error={errors.roles?.[0]} />
        {save.isError && Object.keys(errors).length === 0 && <Alert>{errorMessage(save.error)}</Alert>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>
            Annuler
          </Button>
          <Button type="submit" loading={save.isPending} disabled={!roles.length}>
            Enregistrer
          </Button>
        </div>
      </form>
    </Modal>
  )
}

function PasswordModal({ user, onClose }: { user: User; onClose: () => void }) {
  const invalidate = useInvalidateUsers()
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [mustChange, setMustChange] = useState(true)
  const save = useMutation({
    mutationFn: () => api.put(`/users/${user.id}/password`, { password, password_confirmation: confirmation, must_change_password: mustChange }),
    onSuccess: () => {
      invalidate()
      onClose()
    },
  })
  const errors = validationErrors(save.error)

  return (
    <Modal open title={`Nouveau mot de passe pour ${user.name}`} onClose={onClose}>
      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault()
          save.mutate()
        }}
      >
        <p className="text-sm text-slate-600">Ses sessions ouvertes seront fermées.</p>
        <PasswordFields
          password={password}
          confirmation={confirmation}
          onChange={(p, c) => {
            setPassword(p)
            setConfirmation(c)
          }}
          error={errors.password?.[0]}
          allowGenerate
        />
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={mustChange} onChange={(e) => setMustChange(e.target.checked)} /> Demander de le changer à la prochaine connexion
        </label>
        {save.isError && Object.keys(errors).length === 0 && <Alert>{errorMessage(save.error)}</Alert>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose}>
            Annuler
          </Button>
          <Button type="submit" loading={save.isPending} disabled={!isStrongPassword(password) || password !== confirmation}>
            Enregistrer
          </Button>
        </div>
      </form>
    </Modal>
  )
}

/** ADM-01 : comptes utilisateurs, gérés par l'administrateur et les RH. */
export default function UsersPage() {
  const { user: me } = useAuth()
  const invalidate = useInvalidateUsers()
  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState('')
  const [role, setRole] = useState('')
  const [status, setStatus] = useState('')
  const [modal, setModal] = useState<{ kind: 'create'; employee?: number } | { kind: 'edit' | 'password'; user: User } | null>(() => {
    const employee = Number(params.get('employe'))
    return employee ? { kind: 'create', employee } : null
  })

  const query = new URLSearchParams(Object.entries({ search, role, status }).filter(([, v]) => v)).toString()
  const { data, isLoading } = useQuery({
    queryKey: ['users', query],
    queryFn: async () => (await api.get<UsersResponse>(`/users?${query}`)).data,
    placeholderData: keepPreviousData,
  })

  const toggle = useMutation({
    mutationFn: (u: User) => api.post(`/users/${u.id}/${u.is_active ? 'deactivate' : 'activate'}`),
    onSuccess: invalidate,
  })

  const closeModal = () => {
    setModal(null)
    if (params.has('employe')) setParams({}, { replace: true })
  }
  const assignable = data?.assignable_roles ?? []

  return (
    <>
      <PageHeader
        title="Comptes utilisateurs"
        subtitle="Seule l'administration peut inscrire les employés"
        actions={
          <Button onClick={() => setModal({ kind: 'create' })}>
            <UserPlus className="h-4 w-4" /> Inscrire un utilisateur
          </Button>
        }
      />

      <div className="mb-4 grid gap-3 sm:grid-cols-3">
        <div className="relative">
          <Search className="pointer-events-none absolute top-2.5 left-3 h-4 w-4 text-slate-400" />
          <Input className="pl-9" placeholder="Nom ou e-mail…" value={search} onChange={(e) => setSearch(e.target.value)} />
        </div>
        <Select value={role} onChange={(e) => setRole(e.target.value)}>
          <option value="">Tous les rôles</option>
          {Object.entries(roleLabels).map(([v, l]) => (
            <option key={v} value={v}>
              {l}
            </option>
          ))}
        </Select>
        <Select value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">Tous les comptes</option>
          <option value="actif">Actifs</option>
          <option value="desactive">Désactivés</option>
        </Select>
      </div>

      {toggle.isError && (
        <div className="mb-4">
          <Alert>{errorMessage(toggle.error)}</Alert>
        </div>
      )}

      <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
        {isLoading ? (
          <Spinner />
        ) : (
          <table className="min-w-full divide-y divide-slate-200 text-sm">
            <thead className="bg-slate-50 text-left text-xs font-semibold tracking-wide text-slate-500 uppercase">
              <tr>
                <th className="px-4 py-3">Utilisateur</th>
                <th className="px-4 py-3">Rôles</th>
                <th className="px-4 py-3">Dossier</th>
                <th className="px-4 py-3">Dernière connexion</th>
                <th className="px-4 py-3">Statut</th>
                <th className="px-4 py-3" />
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {data?.data.map((u) => (
                <tr key={u.id} className={u.is_active ? '' : 'opacity-60'}>
                  <td className="px-4 py-3">
                    <div className="font-medium">
                      {u.name} {u.id === me?.id && <span className="text-xs font-normal text-slate-400">(vous)</span>}
                    </div>
                    <div className="text-xs text-slate-500">{u.email}</div>
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex flex-wrap gap-1">
                      {u.roles.map((r) => (
                        <Badge key={r} className="bg-brand-50 text-brand-700">
                          {roleLabels[r]}
                        </Badge>
                      ))}
                    </div>
                  </td>
                  <td className="px-4 py-3">
                    {u.employee ? (
                      <Link to={`/employes/${u.employee.id}`} className="text-brand-700 hover:underline">
                        {u.employee.matricule}
                      </Link>
                    ) : (
                      '—'
                    )}
                  </td>
                  <td className="px-4 py-3 text-slate-600">{u.last_login_at ? formatDateTime(u.last_login_at) : 'Jamais'}</td>
                  <td className="px-4 py-3">
                    {!u.is_active ? (
                      <Badge className="bg-slate-200 text-slate-600">Désactivé</Badge>
                    ) : u.must_change_password ? (
                      <Badge className="bg-amber-100 text-amber-800">Mot de passe à changer</Badge>
                    ) : (
                      <Badge className="bg-emerald-100 text-emerald-800">Actif</Badge>
                    )}
                  </td>
                  <td className="px-4 py-3 text-right whitespace-nowrap">
                    {u.can_manage && (
                      <>
                        <button className="p-1.5 text-slate-400 hover:text-brand-700" title="Modifier" onClick={() => setModal({ kind: 'edit', user: u })}>
                          <Pencil className="h-4 w-4" />
                        </button>
                        <button className="p-1.5 text-slate-400 hover:text-brand-700" title="Nouveau mot de passe" onClick={() => setModal({ kind: 'password', user: u })}>
                          <KeyRound className="h-4 w-4" />
                        </button>
                        <button
                          className={`p-1.5 ${u.is_active ? 'text-slate-400 hover:text-rose-600' : 'text-emerald-600 hover:text-emerald-700'}`}
                          title={u.is_active ? 'Désactiver' : 'Réactiver'}
                          onClick={() => (!u.is_active || confirm(`Désactiver le compte de ${u.name} ? Il sera déconnecté immédiatement.`)) && toggle.mutate(u)}
                        >
                          <Power className="h-4 w-4" />
                        </button>
                      </>
                    )}
                  </td>
                </tr>
              ))}
              {data?.data.length === 0 && (
                <tr>
                  <td colSpan={6} className="px-4 py-10 text-center text-slate-500">
                    Aucun compte.{' '}
                    <button className="text-brand-700 hover:underline" onClick={() => setModal({ kind: 'create' })}>
                      <Plus className="inline h-3.5 w-3.5" /> Inscrire un utilisateur
                    </button>
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        )}
      </div>

      {modal?.kind === 'create' && assignable.length > 0 && <CreateModal assignable={assignable} preselectedEmployee={modal.employee} onClose={closeModal} />}
      {modal?.kind === 'edit' && <EditModal user={modal.user} assignable={assignable} onClose={closeModal} />}
      {modal?.kind === 'password' && <PasswordModal user={modal.user} onClose={closeModal} />}
    </>
  )
}
