import { Check, Eye, EyeOff, X } from 'lucide-react'
import { useState } from 'react'
import { PASSWORD_RULES as RULES, generatePassword } from '../lib/password'
import { Field, Input } from './ui'

interface Props {
  password: string
  confirmation: string
  onChange: (password: string, confirmation: string) => void
  error?: string
  label?: string
  allowGenerate?: boolean
}

/** Mot de passe + confirmation, avec les règles cochées au fil de la saisie. */
export default function PasswordFields({ password, confirmation, onChange, error, label = 'Mot de passe', allowGenerate }: Props) {
  const [visible, setVisible] = useState(false)
  const mismatch = confirmation !== '' && confirmation !== password

  return (
    <div className="space-y-3">
      <Field label={label} required error={error}>
        <div className="relative">
          <Input
            type={visible ? 'text' : 'password'}
            autoComplete="new-password"
            value={password}
            onChange={(e) => onChange(e.target.value, confirmation)}
            className="pr-10"
          />
          <button
            type="button"
            onClick={() => setVisible(!visible)}
            className="absolute top-2 right-2.5 text-slate-400 hover:text-slate-600"
            aria-label={visible ? 'Masquer' : 'Afficher'}
          >
            {visible ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
          </button>
        </div>
      </Field>
      {allowGenerate && (
        <button
          type="button"
          className="text-xs text-brand-700 hover:underline"
          onClick={() => {
            const p = generatePassword()
            setVisible(true)
            onChange(p, p)
          }}
        >
          Générer un mot de passe
        </button>
      )}
      <ul className="grid grid-cols-1 gap-1 text-xs sm:grid-cols-2">
        {RULES.map((r) => {
          const ok = r.test(password)
          return (
            <li key={r.label} className={`flex items-center gap-1.5 ${ok ? 'text-emerald-700' : 'text-slate-500'}`}>
              {ok ? <Check className="h-3.5 w-3.5" /> : <X className="h-3.5 w-3.5" />} {r.label}
            </li>
          )
        })}
      </ul>
      <Field label="Confirmation" required error={mismatch ? 'Les deux mots de passe ne correspondent pas.' : undefined}>
        <Input type={visible ? 'text' : 'password'} autoComplete="new-password" value={confirmation} onChange={(e) => onChange(password, e.target.value)} />
      </Field>
    </div>
  )
}
