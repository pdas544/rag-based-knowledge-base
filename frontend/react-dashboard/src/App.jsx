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
  return (
    <div className="flex-1 p-6 max-w-6xl mx-auto w-full space-y-6">
      <div className="flex justify-between items-center">
        <div>
          <h2 className="text-2xl font-bold text-slate-800 flex items-center gap-2">
            <ShieldCheck className="w-6 h-6 text-emerald-600" />
            Admin Document Management
          </h2>
          <p className="text-sm text-slate-500">
            Upload, update, or remove knowledge base documents and trigger vector embedding pipelines.
          </p>
        </div>
        <button className="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-lg shadow-sm flex items-center gap-2 text-sm transition">
          <FileText className="w-4 h-4" /> Upload Document
        </button>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div className="p-4 bg-white border border-slate-200 rounded-lg shadow-sm">
          <div className="text-slate-500 text-xs font-semibold uppercase">Total Documents</div>
          <div className="text-2xl font-bold text-slate-800 mt-1">0</div>
        </div>
        <div className="p-4 bg-white border border-slate-200 rounded-lg shadow-sm">
          <div className="text-slate-500 text-xs font-semibold uppercase">Vector Chunks in Qdrant</div>
          <div className="text-2xl font-bold text-slate-800 mt-1">0</div>
        </div>
        <div className="p-4 bg-white border border-slate-200 rounded-lg shadow-sm">
          <div className="text-slate-500 text-xs font-semibold uppercase">Processing Queue</div>
          <div className="text-2xl font-bold text-slate-800 mt-1">Idle</div>
        </div>
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