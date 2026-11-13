import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import Layout from './components/Layout'
import RequireAuth from './components/RequireAuth'
import DashboardPage from './pages/DashboardPage'
import EmployeeDetailPage from './pages/EmployeeDetailPage'
import EmployeeFormPage from './pages/EmployeeFormPage'
import EmployeeImportPage from './pages/EmployeeImportPage'
import EmployeesPage from './pages/EmployeesPage'
import LoginPage from './pages/LoginPage'
import PayrollPage from './pages/PayrollPage'
import UsersPage from './pages/UsersPage'
import DocumentsAdminPage from './pages/documents/DocumentsAdminPage'
import PointagePage from './pages/attendance/PointagePage'
import PresencesPage from './pages/attendance/PresencesPage'
import MyDocumentsPage from './pages/documents/MyDocumentsPage'
import ChangePasswordPage from './pages/auth/ChangePasswordPage'
import FirstLoginRoute from './pages/auth/FirstLoginRoute'
import ForgotPasswordPage from './pages/auth/ForgotPasswordPage'
import ResetPasswordPage from './pages/auth/ResetPasswordPage'
import NotificationPreferencesPage from './pages/NotificationPreferencesPage'
import OrganisationPage from './pages/OrganisationPage'
import ApprovalsPage from './pages/leaves/ApprovalsPage'
import LeaveCalendarPage from './pages/leaves/LeaveCalendarPage'
import LeaveSettingsPage from './pages/leaves/LeaveSettingsPage'
import MyLeavesPage from './pages/leaves/MyLeavesPage'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: { retry: 1, refetchOnWindowFocus: false },
  },
})

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <Routes>
          <Route path="/connexion" element={<LoginPage />} />
          <Route path="/mot-de-passe-oublie" element={<ForgotPasswordPage />} />
          <Route path="/definir-mot-de-passe" element={<ResetPasswordPage />} />
          <Route path="/premiere-connexion" element={<FirstLoginRoute />} />
          <Route
            element={
              <RequireAuth>
                <Layout />
              </RequireAuth>
            }
          >
            <Route index element={<DashboardPage />} />
            <Route
              path="employes"
              element={
                <RequireAuth roles={['super-admin', 'rh', 'manager', 'direction']}>
                  <EmployeesPage />
                </RequireAuth>
              }
            />
            <Route
              path="employes/nouveau"
              element={
                <RequireAuth roles={['super-admin', 'rh']}>
                  <EmployeeFormPage />
                </RequireAuth>
              }
            />
            <Route
              path="employes/import"
              element={
                <RequireAuth roles={['super-admin', 'rh']}>
                  <EmployeeImportPage />
                </RequireAuth>
              }
            />
            <Route path="employes/:id" element={<EmployeeDetailPage />} />
            <Route
              path="employes/:id/modifier"
              element={
                <RequireAuth roles={['super-admin', 'rh']}>
                  <EmployeeFormPage />
                </RequireAuth>
              }
            />
            <Route
              path="organisation"
              element={
                <RequireAuth roles={['super-admin', 'rh']}>
                  <OrganisationPage />
                </RequireAuth>
              }
            />
            <Route path="notifications/preferences" element={<NotificationPreferencesPage />} />
            <Route path="mon-compte/mot-de-passe" element={<ChangePasswordPage />} />
            <Route
              path="paie"
              element={
                <RequireAuth roles={['super-admin', 'rh']}>
                  <PayrollPage />
                </RequireAuth>
              }
            />
            <Route path="pointage" element={<PointagePage />} />
            <Route
              path="presences"
              element={
                <RequireAuth roles={['super-admin', 'rh', 'manager', 'direction']}>
                  <PresencesPage />
                </RequireAuth>
              }
            />
            <Route path="documents" element={<MyDocumentsPage />} />
            <Route
              path="documents/administration"
              element={
                <RequireAuth roles={['super-admin', 'rh']}>
                  <DocumentsAdminPage />
                </RequireAuth>
              }
            />
            <Route
              path="administration/comptes"
              element={
                <RequireAuth roles={['super-admin', 'rh']}>
                  <UsersPage />
                </RequireAuth>
              }
            />
            <Route path="conges" element={<MyLeavesPage />} />
            <Route
              path="conges/validations"
              element={
                <RequireAuth roles={['super-admin', 'rh', 'manager']}>
                  <ApprovalsPage />
                </RequireAuth>
              }
            />
            <Route
              path="conges/calendrier"
              element={
                <RequireAuth roles={['super-admin', 'rh', 'manager', 'direction']}>
                  <LeaveCalendarPage />
                </RequireAuth>
              }
            />
            <Route
              path="conges/parametres"
              element={
                <RequireAuth roles={['super-admin', 'rh']}>
                  <LeaveSettingsPage />
                </RequireAuth>
              }
            />
          </Route>
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </BrowserRouter>
    </QueryClientProvider>
  )
}
