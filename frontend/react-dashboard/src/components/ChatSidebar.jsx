import { MessageSquare, Plus } from 'lucide-react';
import { useChat } from '../context/ChatContext';
import DocumentUploadZone from './DocumentUploadZone';

import { useEffect } from 'react';

// Phase 1: list + create + quota badge (search/rename lands in Phase 3).
export default function ChatSidebar() {
  const { conversations, activeId, selectConversation, refreshConversations, createConversation, quota, refreshQuota } = useChat();

  useEffect(() => {
    refreshConversations();
    refreshQuota();
  }, [refreshConversations, refreshQuota]);

  return (
    <aside className="w-64 shrink-0 bg-white border-r border-slate-200 flex flex-col">
      <div className="p-3 space-y-2">
        <button
          onClick={createConversation}
          className="w-full px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg flex items-center justify-center gap-2 transition"
        >
          <Plus className="w-4 h-4" /> New chat
        </button>
        {quota && (
          <p className="text-[11px] text-slate-500 text-center">
            {quota.prompts_used}/{quota.prompts_limit} prompts today
          </p>
        )}
      </div>
      <div className="flex-1 overflow-y-auto px-2 pb-4 space-y-1">
        {conversations.length === 0 && (
          <p className="text-xs text-slate-400 px-2 py-4 text-center">No conversations yet</p>
        )}
        {conversations.map((c) => (
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
