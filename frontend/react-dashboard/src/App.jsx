import { useCallback, useEffect, useState } from 'react';
import { BrowserRouter, Routes, Route, Link, Navigate, useNavigate } from 'react-router-dom';
import { setLoadingGlobalFn, setUnauthorizedHandlerFn } from './axios';
import api from './axios'; // Import our axios instance
import toast from 'react-hot-toast';
import { ShieldCheck, FileText, Database } from 'lucide-react';
import Login from './pages/Login';
import Register from './pages/Register';
import Chat from './pages/Chat';

const getStoredAuth = () => {
  const token = localStorage.getItem('token');
  const storedUser = localStorage.getItem('user');

  if (!token || !storedUser) {
    return { token: null, user: null };
  }

  try {
    return { token, user: JSON.parse(storedUser) };
  } catch {
    localStorage.removeItem('token');
    localStorage.removeItem('user');
    return { token: null, user: null };
  }
};

function AdminDashboard() {
  const [stats, setStats] = useState(null);
  const [reviews, setReviews] = useState([]);

  const authHeaders = () => ({
    Accept: 'application/json',
    'Content-Type': 'application/json',
    Authorization: `Bearer ${localStorage.getItem('token')}`,
  });

  const load = useCallback(async () => {
    const [s, r] = await Promise.all([
      fetch('http://localhost:82/api/admin/documents/stats', { headers: authHeaders() }),
      fetch('http://localhost:82/api/admin/documents?status=awaiting_review', { headers: authHeaders() }),
    ]);
    if (s.ok) setStats(await s.json());
    if (r.ok) setReviews((await r.json()).data ?? []);
  }, []);

  // Fetch-on-mount: intentional (admin stats + review queue).
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    load();
  }, [load]);

  const decide = async (id, action) => {
    const res = await fetch(`http://localhost:82/api/admin/documents/${id}/${action}`, {
      method: 'POST',
      headers: authHeaders(),
      body: JSON.stringify({}),
    });
    if (res.ok) {
      toast.success(`Document ${action}d`);
      load();
    } else {
      toast.error(`Failed to ${action} document`);
    }
  };

  const cards = [
    ['Total Documents', stats?.total_documents ?? '—'],
    ['Vector Chunks in Qdrant', stats?.vector_chunks ?? '—'],
    [`Pending Reviews (${stats?.pending_reviews ?? '—'})`, stats?.processing ? `${stats.processing} processing` : 'Idle'],
  ];

  // 3.3 Token usage row (DB-backed aggregates from /stats)
  const usageCards = [
    ['Tokens Used', stats?.total_tokens_used ?? '—'],
    ['Avg Tokens / Msg', stats?.avg_tokens_per_message ?? '—'],
    ['Est. Cost (USD)', stats?.estimated_cost_usd != null ? `$${stats.estimated_cost_usd}` : '—'],
  ];

  return (
    <div className="flex-1 p-6 max-w-6xl mx-auto w-full space-y-6">
      <div className="flex justify-between items-center">
        <div>
          <h2 className="text-2xl font-bold text-slate-800 flex items-center gap-2">
            <ShieldCheck className="w-6 h-6 text-emerald-600" />
            Admin Document Management
          </h2>
          <p className="text-sm text-slate-500">
            Review staged uploads before they join the global knowledge base.
          </p>
        </div>
        <button className="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg shadow-sm flex items-center gap-2 text-sm transition">
          <FileText className="w-4 h-4" /> Upload Document
        </button>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        {cards.map(([label, value]) => (
          <div key={label} className="p-4 bg-white border border-slate-200 rounded-lg shadow-sm">
            <div className="text-slate-500 text-xs font-semibold uppercase">{label}</div>
            <div className="text-2xl font-bold text-slate-800 mt-1">{value}</div>
          </div>
        ))}
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        {usageCards.map(([label, value]) => (
          <div key={label} className="p-4 bg-white border border-slate-200 rounded-lg shadow-sm">
            <div className="text-slate-500 text-xs font-semibold uppercase">{label}</div>
            <div className="text-2xl font-bold text-slate-800 mt-1">{value}</div>
          </div>
        ))}
      </div>

      <div className="bg-white border border-slate-200 rounded-lg shadow-sm overflow-hidden">
        <div className="px-4 py-3 border-b border-slate-200 font-semibold text-sm text-slate-700">
          Awaiting Review ({reviews.length})
        </div>
        {reviews.length === 0 ? (
          <p className="px-4 py-6 text-sm text-slate-400 text-center">Nothing awaiting review.</p>
        ) : (
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-xs text-slate-500 uppercase border-b border-slate-100">
                <th className="px-4 py-2">File</th>
                <th className="px-4 py-2">User</th>
                <th className="px-4 py-2">Chunks</th>
                <th className="px-4 py-2 text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {reviews.map((d) => (
                <tr key={d.id} className="border-b border-slate-100 last:border-0">
                  <td className="px-4 py-2 truncate max-w-xs">{d.filename}</td>
                  <td className="px-4 py-2 text-slate-500">{d.user?.email ?? `#${d.user_id}`}</td>
                  <td className="px-4 py-2">{d.chunk_count}</td>
                  <td className="px-4 py-2 text-right space-x-2">
                    <button
                      onClick={() => decide(d.id, 'approve')}
                      className="px-3 py-1 bg-emerald-600 hover:bg-emerald-700 text-white text-xs rounded-md"
                    >
                      Approve
                    </button>
                    <button
                      onClick={() => decide(d.id, 'reject')}
                      className="px-3 py-1 bg-red-600 hover:bg-red-700 text-white text-xs rounded-md"
                    >
                      Reject
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}

function ProtectedRoute({ auth, allowedRoles, children }) {
  if (!auth.token || !auth.user) {
    return <Navigate to="/login" replace />;
  }

  if (allowedRoles && !allowedRoles.includes(auth.user.role)) {
    return <Navigate to="/chat" replace />;
  }

  return children;
}

function Logout({ onLogout }) {
  useEffect(() => {
    onLogout();
  }, [onLogout]);

  return <Navigate to="/login" replace />;
}

function AppRoutes() {
  const [auth, setAuth] = useState(getStoredAuth);
  const navigate = useNavigate();

  const handleLogin = useCallback((user, token) => {
    localStorage.setItem('token', token);
    localStorage.setItem('user', JSON.stringify(user));
    setAuth({ user, token });
  }, []);

  const handleRegister = useCallback((user, token) => {
    localStorage.setItem('token', token);
    localStorage.setItem('user', JSON.stringify(user));
    setAuth({ user, token });
    if (user.role === 'admin') {
      navigate('/admin');
    } else {
      navigate('/chat');
    }
  }, [navigate]);

  const handleLogout = useCallback(async () => {
    const token = localStorage.getItem('token');

    if (token) {
      try {
        await api.post(
          '/logout',
          {},
          {
            headers: {
              Accept: 'application/json',
              Authorization: `Bearer ${token}`,
            },
          }
        );
      } catch {
        // Local logout should still complete if the token is already expired or revoked.
      }
    }

    localStorage.removeItem('token');
    localStorage.removeItem('user');
    setAuth({ token: null, user: null });
    toast.success('Logged out successfully');
    navigate('/login', { replace: true });
  }, [navigate]);

  const dashboardPath = auth.user?.role === 'admin' ? '/admin' : '/chat';

  // Let's add a loading state directly here
  const [loading, setLoading] = useState(false);
  
  useEffect(() => {
    setLoadingGlobalFn(setLoading);
    setUnauthorizedHandlerFn(() => {
      handleLogout();
    });
  }, [setLoading, handleLogout]);

  return (
    <div className="min-h-screen bg-slate-100 flex flex-col font-sans text-slate-900">
      <header className="bg-white border-b border-slate-200 px-6 py-4 flex items-center justify-between">
        <div className="font-bold text-lg text-indigo-600 flex items-center gap-2">
          <Database className="w-5 h-5" />
          AI-KnowledgeBase
        </div>
        <nav className="flex gap-4">
          {auth.token && auth.user ? (
            <>
              <Link
                to={dashboardPath}
                className="px-3 py-1.5 rounded-md text-sm font-medium text-slate-600 hover:text-indigo-600 hover:bg-slate-50 transition"
              >
                Dashboard
              </Link>
              <Link
                to="/logout"
                className="px-3 py-1.5 rounded-md text-sm font-medium text-slate-600 hover:text-indigo-600 hover:bg-slate-50 transition"
              >
                Logout
              </Link>
            </>
          ) : (
            <>
            <Link
              to="/login"
              className="px-3 py-1.5 rounded-md text-sm font-medium text-slate-600 hover:text-indigo-600 hover:bg-slate-50 transition"
            >
              Login
            </Link>
            <Link
              to="/register"
              className="px-3 py-1.5 rounded-md text-sm font-medium text-slate-600 hover:text-indigo-600 hover:bg-slate-50 transition"
            >
              Register
              </Link>
            </>
            
          )}
        </nav>
      </header>

      <main className="flex justify-center flex-1 p-6">
        {loading && (
          <div className="absolute inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div className="text-white text-lg">
              Loading...
            </div>
          </div>
        )}
        <Routes>
          <Route path="/" element={<Navigate to={auth.token ? dashboardPath : '/login'} replace />} />
          <Route
            path="/chat"
            element={(
              <ProtectedRoute auth={auth}>
                <Chat />
              </ProtectedRoute>
            )}
          />
          <Route
            path="/admin"
            element={(
              <ProtectedRoute auth={auth} allowedRoles={['admin']}>
                <AdminDashboard />
              </ProtectedRoute>
            )}
          />
          <Route
            path="/login"
            element={auth.token ? <Navigate to={dashboardPath} replace /> : <Login onLogin={handleLogin} />}
          />
          <Route
            path="/register"
            element={auth.token ? <Navigate to={dashboardPath} replace /> : <Register onRegister={handleRegister} />}
          />
          <Route path="/logout" element={<Logout onLogout={handleLogout} />} />
          <Route path="*" element={<Navigate to={auth.token ? dashboardPath : '/login'} replace />} />
        </Routes>
      </main>
    </div>
  );
}

export default function App() {
  return (
    <BrowserRouter>
      <AppRoutes />
    </BrowserRouter>
  );
}