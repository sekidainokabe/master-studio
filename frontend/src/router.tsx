import { createBrowserRouter } from 'react-router'
import { AppLayout } from './components/AppLayout'
import { RequireAuth } from './components/RequireAuth'
import { ChangesetDetailPage } from './pages/ChangesetDetailPage'
import { ChangesetEditPage } from './pages/ChangesetEditPage'
import { ChangesetNewPage } from './pages/ChangesetNewPage'
import { ChangesetsPage } from './pages/ChangesetsPage'
import { HomePage } from './pages/HomePage'
import { LoginPage } from './pages/LoginPage'
import { MasterDetailPage } from './pages/MasterDetailPage'
import { MastersPage } from './pages/MastersPage'
import { ReleasesPage } from './pages/ReleasesPage'

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
          { path: 'changesets', element: <ChangesetsPage /> },
          // 'new' は ':id' より具体的なので、React Router はこちらを優先して選ぶ
          { path: 'changesets/new', element: <ChangesetNewPage /> },
          { path: 'changesets/:id', element: <ChangesetDetailPage /> },
          { path: 'changesets/:id/edit', element: <ChangesetEditPage /> },
          { path: 'releases', element: <ReleasesPage /> },
        ],
      },
    ],
  },
])
