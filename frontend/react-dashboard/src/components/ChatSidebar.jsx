import { MessageSquare, Plus } from 'lucide-react';
import { useChat } from '../context/ChatContext';

// Phase 0 shell — Phase 1 adds GET /conversations polling, search, rename/delete.
export default function ChatSidebar() {
  const { conversations, activeId, selectConversation } = useChat();

  return (
    <aside className="w-64 shrink-0 bg-white border-r border-slate-200 flex flex-col">
      <div className="p-3">
        <button className="w-full px-3 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg flex items-center justify-center gap-2 transition">
          <Plus className="w-4 h-4" /> New chat
        </button>
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
    </aside>
  );
}
