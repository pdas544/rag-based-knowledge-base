import React from 'react';
import { BrowserRouter, Routes, Route, Link, Navigate } from 'react-router-dom';
import { MessageSquare, ShieldCheck, FileText, Database } from 'lucide-react';
import Login from './pages/Login';

function UserChat() {
  return (
    <div className="flex-1 flex flex-col p-6 max-w-4xl mx-auto w-full">
      <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6 flex-1 flex flex-col">
        <h2 className="text-xl font-semibold text-slate-800 mb-2 flex items-center gap-2">
          <MessageSquare className="w-5 h-5 text-indigo-600" />
          AI Knowledge Base Chat
        </h2>
        <p className="text-sm text-slate-500 mb-6">
          Read-only access: ask questions directly answered from uploaded company knowledge documents.
        </p>
        <div className="flex-1 bg-slate-50 rounded-lg border border-dashed border-slate-200 p-6 flex items-center justify-center text-slate-400">
          Chat history will appear here...
        </div>
      </div>
    </div>
  );
}

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

export default function App() {
  return (
    <BrowserRouter>
      <div className="min-h-screen bg-slate-100 flex flex-col font-sans text-slate-900">
        <header className="bg-white border-b border-slate-200 px-6 py-4 flex items-center justify-between">
          <div className="font-bold text-lg text-indigo-600 flex items-center gap-2">
            <Database className="w-5 h-5" />
            AI-KnowledgeBase
          </div>
          <nav className="flex gap-4">
            <Link
              to="/login"
              className="px-3 py-1.5 rounded-md text-sm font-medium text-slate-600 hover:text-indigo-600 hover:bg-slate-50 transition"
            >
              Login
            </Link>
            <Link
              to="/chat"
              className="px-3 py-1.5 rounded-md text-sm font-medium text-slate-600 hover:text-indigo-600 hover:bg-slate-50 transition"
            >
              User Chat
            </Link>
            <Link
              to="/admin"
              className="px-3 py-1.5 rounded-md text-sm font-medium text-slate-600 hover:text-indigo-600 hover:bg-slate-50 transition"
            >
              Admin Dashboard
            </Link>
          </nav>
        </header>

        <main className="flex justify-center flex-1 p-6">
          <Routes>
            <Route path="/" element={<Navigate to="/login" replace />} />
            <Route path="/chat" element={<UserChat />} />
            <Route path="/admin" element={<AdminDashboard />} />
            <Route path="/login" element={<Login />} />
            <Route path="*" element={<Navigate to="/login" replace />} />
          </Routes>
        </main>
      </div>
    </BrowserRouter>
  );
}
