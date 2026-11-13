import { useMutation } from '@tanstack/react-query'
import { AlertTriangle, CheckCircle2, Download, FileSpreadsheet, Upload, XCircle } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api, errorMessage, validationErrors } from '../api/client'
import { Alert, Badge, Button, Card, PageHeader } from '../components/ui'

interface PreviewRow {
  line: number
  data: Record<string, string | number | null>
  errors: string[]
  warnings: string[]
}
interface Preview {
  rows: PreviewRow[]
  valid: number
  invalid: number
  to_create: { departement: string[]; poste: string[]; site: string[] }
}
interface ImportResult {
  created: number
  skipped: number
  contracts: number
}

/** EMP-07 : import des employés en trois étapes — modèle, vérification, import. */
export default function EmployeeImportPage() {
  const [file, setFile] = useState<File | null>(null)
  const [createMissing, setCreateMissing] = useState(false)
  const [onlyValid, setOnlyValid] = useState(false)
  const [filter, setFilter] = useState<'all' | 'errors'>('all')

  const form = (extra: Record<string, string> = {}) => {
    const data = new FormData()
    if (file) data.append('file', file)
    data.append('create_missing', createMissing ? '1' : '0')
    Object.entries(extra).forEach(([k, v]) => data.append(k, v))
    return data
  }

  const preview = useMutation({ mutationFn: async () => (await api.post<Preview>('/employees/import/preview', form())).data })
  const importing = useMutation({
    mutationFn: async () => (await api.post<ImportResult>('/employees/import', form({ only_valid: onlyValid ? '1' : '0' }))).data,
  })

  const reset = () => {
    preview.reset()
    importing.reset()
    setOnlyValid(false)
  }

  const p = preview.data
  const rows = p?.rows.filter((r) => filter === 'all' || r.errors.length > 0) ?? []
  const toCreate = p ? [...p.to_create.departement.map((n) => `département « ${n} »`), ...p.to_create.poste.map((n) => `poste « ${n} »`), ...p.to_create.site.map((n) => `site « ${n} »`)] : []
  const fileError = validationErrors(preview.error).file?.[0] ?? validationErrors(importing.error).file?.[0]

  if (importing.isSuccess) {
    const r = importing.data
    return (
      <>
        <PageHeader title="Importer des employés" />
        <Card>
          <div className="flex flex-col items-center gap-3 py-6 text-center">
            <CheckCircle2 className="h-12 w-12 text-emerald-600" />
            <p className="text-lg font-semibold">{r.created} employé(s) importé(s)</p>
            <p className="text-sm text-slate-600">
              {r.contracts} contrat(s) créé(s){r.skipped > 0 && ` · ${r.skipped} ligne(s) en erreur ignorée(s)`}
            </p>
            <p className="text-sm text-slate-500">Pensez à créer leurs comptes de connexion depuis « Comptes utilisateurs ».</p>
            <div className="mt-2 flex gap-2">
              <Link to="/employes">
                <Button>Voir la liste des employés</Button>
              </Link>
              <Button
                variant="secondary"
                onClick={() => {
                  setFile(null)
                  reset()
                }}
              >
                Importer un autre fichier
              </Button>
            </div>
          </div>
        </Card>
      </>
    )
  }

  return (
    <>
      <PageHeader title="Importer des employés" subtitle="Depuis un fichier Excel (.xlsx) ou CSV, 2 000 lignes maximum" />

      <div className="grid gap-6 lg:grid-cols-2">
        <Card title="1. Préparer le fichier">
          <p className="text-sm text-slate-600">
            Téléchargez le modèle et remplissez une ligne par employé. Les colonnes marquées d'une * sont obligatoires ; l'onglet « Valeurs acceptées » liste les départements, postes et sites
            existants. Un fichier avec d'autres en-têtes fonctionne aussi s'il garde les mêmes intitulés.
          </p>
          <a href="/api/v1/employees/import/template" className="mt-4 inline-block">
            <Button variant="secondary" type="button">
              <Download className="h-4 w-4" /> Télécharger le modèle Excel
            </Button>
          </a>
        </Card>

        <Card title="2. Vérifier le fichier">
          <label className="flex cursor-pointer items-center gap-3 rounded-md border border-dashed border-slate-300 p-4 hover:bg-slate-50">
            <FileSpreadsheet className="h-8 w-8 text-emerald-600" />
            <span className="text-sm">
              {file ? <strong>{file.name}</strong> : 'Choisir un fichier .xlsx ou .csv'}
              <span className="block text-xs text-slate-500">5 Mo maximum</span>
            </span>
            <input
              type="file"
              accept=".xlsx,.csv"
              hidden
              onChange={(e) => {
                setFile(e.target.files?.[0] ?? null)
                reset()
              }}
            />
          </label>
          <label className="mt-3 flex items-start gap-2 text-sm">
            <input
              type="checkbox"
              className="mt-1"
              checked={createMissing}
              onChange={(e) => {
                setCreateMissing(e.target.checked)
                reset()
              }}
            />
            <span>
              Créer les départements, postes et sites inconnus
              <span className="block text-xs text-slate-500">Sinon, une valeur inconnue est signalée comme erreur.</span>
            </span>
          </label>
          <Button className="mt-4" disabled={!file} loading={preview.isPending} onClick={() => preview.mutate()}>
            Vérifier le fichier
          </Button>
        </Card>
      </div>

      {fileError && (
        <div className="mt-6">
          <Alert>{fileError}</Alert>
        </div>
      )}
      {(preview.isError || importing.isError) && !fileError && (
        <div className="mt-6">
          <Alert>{errorMessage(preview.error ?? importing.error)}</Alert>
        </div>
      )}

      {p && (
        <Card
          title="3. Résultat de la vérification"
          className="mt-6"
          actions={
            <div className="flex gap-2 text-sm">
              <Badge className="bg-emerald-100 text-emerald-800">{p.valid} valide(s)</Badge>
              {p.invalid > 0 && <Badge className="bg-rose-100 text-rose-800">{p.invalid} en erreur</Badge>}
            </div>
          }
        >
          {toCreate.length > 0 && (
            <div className="mb-4">
              <Alert tone="success">Seront créés : {toCreate.join(', ')}.</Alert>
            </div>
          )}

          {p.invalid > 0 && (
            <div className="mb-3 flex gap-2 text-sm">
              <button className={filter === 'all' ? 'font-semibold text-brand-700' : 'text-slate-500'} onClick={() => setFilter('all')}>
                Toutes les lignes
              </button>
              ·
              <button className={filter === 'errors' ? 'font-semibold text-brand-700' : 'text-slate-500'} onClick={() => setFilter('errors')}>
                Lignes en erreur
              </button>
            </div>
          )}

          <div className="max-h-[28rem] overflow-auto rounded border border-slate-200">
            <table className="min-w-full text-sm">
              <thead className="sticky top-0 bg-slate-50 text-left text-xs font-semibold text-slate-500 uppercase">
                <tr>
                  <th className="px-3 py-2">Ligne</th>
                  <th className="px-3 py-2">Employé</th>
                  <th className="px-3 py-2">E-mail</th>
                  <th className="px-3 py-2">Embauche</th>
                  <th className="px-3 py-2">Contrôle</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((r) => (
                  <tr key={r.line} className={r.errors.length ? 'bg-rose-50/60' : ''}>
                    <td className="px-3 py-2 text-slate-500">{r.line}</td>
                    <td className="px-3 py-2">
                      {String(r.data.nom ?? '').toUpperCase()} {r.data.prenom}
                    </td>
                    <td className="px-3 py-2 text-slate-600">{r.data.email_pro ?? '—'}</td>
                    <td className="px-3 py-2 text-slate-600">{r.data.date_embauche ? String(r.data.date_embauche).split('-').reverse().join('/') : '—'}</td>
                    <td className="px-3 py-2">
                      {r.errors.length === 0 && r.warnings.length === 0 && <CheckCircle2 className="h-4 w-4 text-emerald-600" />}
                      {r.errors.map((e) => (
                        <div key={e} className="flex items-start gap-1 text-rose-700">
                          <XCircle className="mt-0.5 h-3.5 w-3.5 shrink-0" /> {e}
                        </div>
                      ))}
                      {r.warnings.map((w) => (
                        <div key={w} className="flex items-start gap-1 text-amber-700">
                          <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" /> {w}
                        </div>
                      ))}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
            {p.invalid > 0 ? (
              <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={onlyValid} onChange={(e) => setOnlyValid(e.target.checked)} />
                Importer seulement les {p.valid} ligne(s) valide(s) et ignorer les erreurs
              </label>
            ) : (
              <span className="text-sm text-emerald-700">Aucune erreur : le fichier peut être importé.</span>
            )}
            <Button disabled={p.valid === 0 || (p.invalid > 0 && !onlyValid)} loading={importing.isPending} onClick={() => importing.mutate()}>
              <Upload className="h-4 w-4" /> Importer {p.valid} employé(s)
            </Button>
          </div>
        </Card>
      )}
    </>
  )
}
