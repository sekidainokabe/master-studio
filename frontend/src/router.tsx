import { createBrowserRouter } from 'react-router'
import { AppLayout } from './components/AppLayout'
import { RequireAuth } from './components/RequireAuth'
import { HomePage } from './pages/HomePage'
import { LoginPage } from './pages/LoginPage'
import { MasterDetailPage } from './pages/MasterDetailPage'
import { MastersPage } from './pages/MastersPage'

export const router = createBrowserRouter([
  { path: '/login', element: <LoginPage /> },
  {
    // ここから下のページはログインが必要
    element: <RequireAuth />,
    children: [
      {
        element: <AppLayout />,
        children: [
          { index: true, element: <HomePage /> },
          { path: 'masters', element: <MastersPage /> },
          { path: 'masters/:key', element: <MasterDetailPage /> },
        ],
      },
    ],
  },
])
