import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, errorMessage, validationErrors } from '../api/client'
import { useDepartments, useEmployeeOptions, usePositions, useSites, useWorkSchedules } from '../api/queries'
import type { Employee, Role } from '../api/types'
import { useAuth } from '../auth/useAuth'
import PasswordFields from '../components/PasswordFields'
import { isStrongPassword } from '../lib/password'
import { Alert, Button, Card, Field, Input, PageHeader, Select, Spinner } from '../components/ui'
import { maritalLabels, roleLabels, statusLabels } from '../lib/labels'

type FormValues = Record<string, string>

const FIELDS = [
  'civility', 'last_name', 'first_name', 'birth_date', 'birth_place', 'nationality', 'social_security_number',
  'marital_status', 'children_count', 'personal_email', 'work_email', 'phone', 'address', 'city', 'postal_code',
  'emergency_contact_name', 'emergency_contact_phone', 'iban', 'department_id', 'position_id', 'site_id',
  'manager_id', 'work_schedule_id', 'hire_date', 'status', 'exit_date', 'exit_reason',
] as const

const STEPS = ['Identité', 'Coordonnées', 'Poste', 'Banque et urgence'] as const
const ACCOUNT_STEP = 'Compte de connexion'

function toForm(e?: Employee): FormValues {
  const values: FormValues = {}
  for (const f of FIELDS) {
    const v = e?.[f as keyof Employee]
    values[f] = v === null || v === undefined ? '' : String(v)
  }
  if (!e) {
    values.status = 'periode_essai'
    values.children_count = '0'
  }
  return values
}

/** Les chaînes vides sont envoyées comme null pour que Laravel les accepte en "nullable". */
function toPayload(values: FormValues) {
  return Object.fromEntries(Object.entries(values).map(([k, v]) => [k, v === '' ? null : v]))
}

export default function EmployeeFormPage() {
  const { id } = useParams()
  const editing = !!id
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [step, setStep] = useState(0)
  const { hasRole } = useAuth()
  const steps: string[] = editing ? [...STEPS] : [...STEPS, ACCOUNT_STEP]

  // ADM-01 : à la création, l'administration peut inscrire l'employé tout de suite (mot de passe saisi ici)
  const [account, setAccount] = useState({
    create: true,
    roles: ['employe'] as Role[],
    password: '',
    password_confirmation: '',
    must_change_password: true,
  })
  const assignableRoles: Role[] = hasRole('super-admin') ? ['employe', 'manager', 'direction', 'rh', 'super-admin'] : ['employe', 'manager', 'direction']
  const accountReady = !account.create || (account.roles.length > 0 && isStrongPassword(account.password) && account.password === account.password_confirmation)

  const { data: employee, isLoading } = useQuery({
    queryKey: ['employee', id],
    queryFn: async () => (await api.get<{ data: Employee }>(`/employees/${id}`)).data.data,
    enabled: editing,
  })
  const { data: departments } = useDepartments()
  const { data: positions } = usePositions()
  const { data: sites } = useSites()
  const { data: managers } = useEmployeeOptions()
  const { data: schedules } = useWorkSchedules()

  const { register, handleSubmit, reset, setError, formState } = useForm<FormValues>({ defaultValues: toForm() })
  useEffect(() => {
    if (employee) reset(toForm(employee))
  }, [employee, reset])

  const save = useMutation({
    mutationFn: async (values: FormValues) => {
      const payload = editing ? toPayload(values) : { ...toPayload(values), account: account.create ? account : { create: false } }
      const { data } = editing
        ? await api.put<{ data: Employee }>(`/employees/${id}`, payload)
        : await api.post<{ data: Employee }>('/employees', payload)
      return data.data
    },
    onSuccess: (saved) => {
      queryClient.invalidateQueries({ queryKey: ['employees'] })
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      queryClient.setQueryData(['employee', String(saved.id)], saved)
      navigate(`/employes/${saved.id}`)
    },
    onError: (error) => {
      const errors = validationErrors(error)
      Object.entries(errors).forEach(([field, messages]) => setError(field, { message: messages[0] }))
      // Revenir à la première étape contenant une erreur
      const firstStep = STEP_FIELDS.findIndex((fields) => fields.some((f) => errors[f]))
      if (firstStep >= 0) setStep(firstStep)
    },
  })

  if (editing && isLoading) return <Spinner />

  const err = (f: string) => formState.errors[f]?.message

  const STEP_CONTENT = [
    <div key="0" className="grid gap-4 sm:grid-cols-2">
      <Field label="Civilité">
        <Select {...register('civility')}>
          <option value="">—</option>
          <option value="M.">M.</option>
          <option value="Mme">Mme</option>
        </Select>
      </Field>
      <div />
      <Field label="Nom" required error={err('last_name')}>
        <Input {...register('last_name', { required: 'Le nom est obligatoire.' })} />
      </Field>
      <Field label="Prénom" required error={err('first_name')}>
        <Input {...register('first_name', { required: 'Le prénom est obligatoire.' })} />
      </Field>
      <Field label="Date de naissance" error={err('birth_date')}>
        <Input type="date" {...register('birth_date')} />
      </Field>
      <Field label="Lieu de naissance" error={err('birth_place')}>
        <Input {...register('birth_place')} />
      </Field>
      <Field label="Nationalité" error={err('nationality')}>
        <Input {...register('nationality')} />
      </Field>
      <Field label="N° de sécurité sociale" error={err('social_security_number')}>
        <Input {...register('social_security_number')} />
      </Field>
      <Field label="Situation familiale" error={err('marital_status')}>
        <Select {...register('marital_status')}>
          <option value="">—</option>
          {Object.entries(maritalLabels).map(([v, l]) => (
            <option key={v} value={v}>
              {l}
            </option>
          ))}
        </Select>
      </Field>
      <Field label="Nombre d'enfants" error={err('children_count')}>
        <Input type="number" min={0} {...register('children_count')} />
      </Field>
    </div>,

    <div key="1" className="grid gap-4 sm:grid-cols-2">
      <Field label="E-mail professionnel" error={err('work_email')}>
        <Input type="email" {...register('work_email')} />
      </Field>
      <Field label="E-mail personnel" error={err('personal_email')}>
        <Input type="email" {...register('personal_email')} />
      </Field>
      <Field label="Téléphone" error={err('phone')}>
        <Input type="tel" {...register('phone')} />
      </Field>
      <div />
      <Field label="Adresse" className="sm:col-span-2" error={err('address')}>
        <Input {...register('address')} />
      </Field>
      <Field label="Code postal" error={err('postal_code')}>
        <Input {...register('postal_code')} />
      </Field>
      <Field label="Ville" error={err('city')}>
        <Input {...register('city')} />
      </Field>
    </div>,

    <div key="2" className="grid gap-4 sm:grid-cols-2">
      <Field label="Poste" error={err('position_id')}>
        <Select {...register('position_id')}>
          <option value="">—</option>
          {positions?.map((p) => (
            <option key={p.id} value={p.id}>
              {p.title}
            </option>
          ))}
        </Select>
      </Field>
      <Field label="Département" error={err('department_id')}>
        <Select {...register('department_id')}>
          <option value="">—</option>
          {departments?.map((d) => (
            <option key={d.id} value={d.id}>
              {d.name}
            </option>
          ))}
        </Select>
      </Field>
      <Field label="Site" error={err('site_id')}>
        <Select {...register('site_id')}>
          <option value="">—</option>
          {sites?.map((s) => (
            <option key={s.id} value={s.id}>
              {s.name}
            </option>
          ))}
        </Select>
      </Field>
      <Field label="Manager" error={err('manager_id')}>
        <Select {...register('manager_id')}>
          <option value="">—</option>
          {managers
            ?.filter((m) => String(m.id) !== id)
            .map((m) => (
              <option key={m.id} value={m.id}>
                {m.label}
              </option>
            ))}
        </Select>
      </Field>
      <Field label="Horaire de travail" error={err('work_schedule_id')}>
        <Select {...register('work_schedule_id')}>
          <option value="">Horaire par défaut{schedules?.find((x) => x.is_default) ? ` (${schedules.find((x) => x.is_default)?.name})` : ''}</option>
          {schedules
            ?.filter((x) => !x.is_default)
            .map((x) => (
              <option key={x.id} value={x.id}>
                {x.name}
              </option>
            ))}
        </Select>
      </Field>
      <div />
      <Field label="Date d'embauche" required error={err('hire_date')}>
        <Input type="date" {...register('hire_date', { required: "La date d'embauche est obligatoire." })} />
      </Field>
      <Field label="Statut" required error={err('status')}>
        <Select {...register('status')}>
          {Object.entries(statusLabels)
            .filter(([v]) => v !== 'sorti' || employee?.status === 'sorti')
            .map(([v, l]) => (
              <option key={v} value={v}>
                {l}
              </option>
            ))}
        </Select>
      </Field>
    </div>,

    <div key="3" className="grid gap-4 sm:grid-cols-2">
      <Field label="IBAN" className="sm:col-span-2" error={err('iban')}>
        <Input {...register('iban')} placeholder="FR76 …" />
      </Field>
      <Field label="Contact d'urgence" error={err('emergency_contact_name')}>
        <Input {...register('emergency_contact_name')} />
      </Field>
      <Field label="Téléphone d'urgence" error={err('emergency_contact_phone')}>
        <Input type="tel" {...register('emergency_contact_phone')} />
      </Field>
    </div>,
  ]

  if (!editing) {
    const accountErrors = validationErrors(save.error)
    STEP_CONTENT.push(
      <div key="4" className="space-y-4">
        <label className="flex items-start gap-2 text-sm">
          <input type="checkbox" className="mt-1" checked={account.create} onChange={(e) => setAccount({ ...account, create: e.target.checked })} />
          <span>
            Créer le compte de connexion maintenant
            <span className="block text-xs text-slate-500">L'identifiant sera l'e-mail professionnel (étape Coordonnées). Vous pourrez aussi le créer plus tard.</span>
          </span>
        </label>
        {account.create && (
          <>
            <Field label="Rôles" required error={accountErrors['account.roles']?.[0] ?? accountErrors.roles?.[0]}>
              <div className="flex flex-wrap gap-x-5 gap-y-2 pt-1">
                {assignableRoles.map((r) => (
                  <label key={r} className="flex items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      checked={account.roles.includes(r)}
                      onChange={(e) => setAccount({ ...account, roles: e.target.checked ? [...account.roles, r] : account.roles.filter((x) => x !== r) })}
                    />
                    {roleLabels[r]}
                  </label>
                ))}
              </div>
            </Field>
            <PasswordFields
              password={account.password}
              confirmation={account.password_confirmation}
              onChange={(password, password_confirmation) => setAccount({ ...account, password, password_confirmation })}
              error={accountErrors['account.password']?.[0]}
              allowGenerate
            />
            <label className="flex items-start gap-2 text-sm">
              <input type="checkbox" className="mt-1" checked={account.must_change_password} onChange={(e) => setAccount({ ...account, must_change_password: e.target.checked })} />
              <span>
                Demander de changer ce mot de passe à la première connexion
                <span className="block text-xs text-slate-500">Recommandé : ainsi, personne d'autre que l'employé ne connaît son mot de passe.</span>
              </span>
            </label>
          </>
        )}
      </div>,
    )
  }

  const last = step === steps.length - 1

  return (
    <>
      <PageHeader
        title={editing ? `Modifier ${employee?.full_name ?? ''}` : 'Nouvel employé'}
        subtitle={editing ? `Matricule ${employee?.matricule}` : 'Le matricule sera attribué automatiquement.'}
      />

      <ol className="mb-6 flex flex-wrap gap-2">
        {steps.map((label, i) => (
          <li key={label}>
            <button
              type="button"
              onClick={() => setStep(i)}
              className={`rounded-full px-3 py-1 text-sm ${i === step ? 'bg-brand-700 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'}`}
            >
              {i + 1}. {label}
            </button>
          </li>
        ))}
      </ol>

      <form
        onSubmit={handleSubmit(
          (v) => save.mutate(v),
          (errors) => {
            const firstStep = STEP_FIELDS.findIndex((fields) => fields.some((f) => errors[f]))
            if (firstStep >= 0) setStep(firstStep)
          },
        )}
      >
        <Card>
          {save.isError && Object.keys(validationErrors(save.error)).length === 0 && (
            <div className="mb-4">
              <Alert>{errorMessage(save.error)}</Alert>
            </div>
          )}
          {STEP_CONTENT.map((content, i) => (
            <div key={i} hidden={i !== step}>
              {content}
            </div>
          ))}
        </Card>

        <div className="mt-6 flex justify-between">
          <Link to={editing ? `/employes/${id}` : '/employes'}>
            <Button type="button" variant="ghost">
              Annuler
            </Button>
          </Link>
          <div className="flex gap-2">
            {step > 0 && (
              <Button type="button" variant="secondary" onClick={() => setStep(step - 1)}>
                Précédent
              </Button>
            )}
            {!last && (
              <Button type="button" variant="secondary" onClick={() => setStep(step + 1)}>
                Suivant
              </Button>
            )}
            <Button type="submit" loading={save.isPending} disabled={!accountReady}>
              Enregistrer
            </Button>
          </div>
        </div>
      </form>
    </>
  )
}

const STEP_FIELDS: string[][] = [
  ['civility', 'last_name', 'first_name', 'birth_date', 'birth_place', 'nationality', 'social_security_number', 'marital_status', 'children_count'],
  ['work_email', 'personal_email', 'phone', 'address', 'postal_code', 'city'],
  ['position_id', 'department_id', 'site_id', 'manager_id', 'work_schedule_id', 'hire_date', 'status'],
  ['iban', 'emergency_contact_name', 'emergency_contact_phone'],
  ['account.password', 'account.roles', 'roles'],
]
