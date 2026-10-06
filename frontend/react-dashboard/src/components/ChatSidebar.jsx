import { MessageSquare, Plus, Search } from 'lucide-react';
import { useChat } from '../context/ChatContext';
import DocumentUploadZone from './DocumentUploadZone';

import { useEffect, useState } from 'react';

export default function ChatSidebar() {
  const { conversations, activeId, selectConversation, refreshConversations, createConversation, quota, refreshQuota } = useChat();
  // Last line of defense: never let a non-array state blank the page.
  const list = Array.isArray(conversations) ? conversations : [];
  const [search, setSearch] = useState('');

  useEffect(() => {
    refreshQuota();
  }, [refreshQuota]);

  // Debounced server search (3.1); empty query restores the full list.
  useEffect(() => {
    const t = setTimeout(() => refreshConversations(search.trim()), 350);

    return () => clearTimeout(t);
  }, [search, refreshConversations]);

  return (
    <aside className="w-64 shrink-0 bg-white border-r border-slate-200 flex flex-col">
      <div className="p-3 space-y-2">
        <button
          onClick={createConversation}
          className="w-full px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg flex items-center justify-center gap-2 transition"
        >
          <Plus className="w-4 h-4" /> New chat
        </button>
        <div className="relative">
          <Search className="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search chats..."
            className="w-full pl-8 pr-2 py-1.5 border border-slate-200 rounded-lg text-xs"
          />
        </div>
        {quota && (
          <p className="text-[11px] text-slate-500 text-center">
            {quota.prompts_used}/{quota.prompts_limit} prompts today
            {' · '}{quota.doc_uploads_limit - quota.doc_uploads_used} docs left
          </p>
        )}
      </div>
      <div className="flex-1 overflow-y-auto px-2 pb-4 space-y-1">
        {list.length === 0 && (
          <p className="text-xs text-slate-400 px-2 py-4 text-center">No conversations yet</p>
        )}
        {list.map((c) => (
          <button
            key={c.id}
            onClick={() => selectConversation(c.id)}
            className={`w-full text-left px-3 py-2 rounded-md text-sm flex items-center gap-2 truncate ${
              c.id === activeId ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:bg-slate-50'
            }`}
          >
            <MessageSquare className="w-4 h-4 shrink-0" />
            <span className="truncate">{c.title || 'Untitled'}</span>
          </button>
        ))}
      </div>
      <DocumentUploadZone />
    </aside>
  );
}
