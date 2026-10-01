import { MessageSquare } from 'lucide-react';
import ChatSidebar from '../components/ChatSidebar';
import { ChatProvider, useChat } from '../context/ChatContext';

function ChatMain() {
  const { messages, streaming } = useChat();

  return (
    <div className="flex-1 flex flex-col p-6 max-w-4xl mx-auto w-full">
      <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6 flex-1 flex flex-col">
        <h2 className="text-xl font-semibold text-slate-800 mb-2 flex items-center gap-2">
          <MessageSquare className="w-5 h-5 text-indigo-600" />
          AI Knowledge Base Chat
        </h2>
        <p className="text-sm text-slate-500 mb-6">
          Ask questions answered from approved global knowledge documents.
        </p>
        <div className="flex-1 bg-slate-50 rounded-lg border border-dashed border-slate-200 p-6 flex items-center justify-center text-slate-400">
          {messages.length === 0 ? 'Chat history will appear here...' : `${messages.length} message(s) — streaming UI lands in Phase 1`}
        </div>
        <div className="mt-4 flex gap-2">
          <input
            disabled={streaming}
            placeholder="Phase 1 wires streaming input..."
            className="flex-1 px-3 py-2 border border-slate-200 rounded-lg text-sm bg-slate-50"
            readOnly
          />
          <button disabled className="px-4 py-2 bg-slate-200 text-slate-500 text-sm rounded-lg">
            Send
          </button>
        </div>
      </div>
    </div>
  );
}

// Phase 0 shell replacing App.jsx UserChat placeholder; Phase 1 adds SSE.
export default function Chat() {
  return (
    <ChatProvider>
      <div className="flex flex-1 w-full">
        <ChatSidebar />
        <ChatMain />
      </div>
    </ChatProvider>
  );
}
