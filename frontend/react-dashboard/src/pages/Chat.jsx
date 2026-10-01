import { MessageSquare } from 'lucide-react';
import ChatSidebar from '../components/ChatSidebar';
import ChatMessage from '../components/ChatMessage';
import ChatInput from '../components/ChatInput';
import { ChatProvider, useChat } from '../context/ChatContext';

function ChatMain() {
  const { messages } = useChat();

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
        <div className="flex-1 overflow-y-auto">
          {messages.length === 0 && (
            <div className="bg-slate-50 rounded-lg border border-dashed border-slate-200 p-6 flex items-center justify-center text-slate-400 text-sm">
              Chat history will appear here...
            </div>
          )}
          {messages.map((m, i) => (
            <ChatMessage key={m.id ?? i} role={m.role} content={m.content} />
          ))}
        </div>
        <ChatInput />
      </div>
    </div>
  );
}

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
