import { Copy } from 'lucide-react';

export default function ChatMessage({ role, content }) {
  const isUser = role === 'user';

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(content);
    } catch {
      // clipboard unavailable
    }
  };

  return (
    <div className={`flex ${isUser ? 'justify-end' : 'justify-start'} mb-3`}>
      <div
        className={`max-w-[80%] px-4 py-2 rounded-lg text-sm whitespace-pre-wrap ${
          isUser ? 'bg-indigo-600 text-white' : 'bg-white border border-slate-200 text-slate-800'
        }`}
      >
        <div>{content}</div>
        {!isUser && (
          <button onClick={copy} className="mt-1 text-xs text-slate-400 hover:text-slate-600 flex items-center gap-1">
            <Copy className="w-3 h-3" /> Copy
          </button>
        )}
      </div>
    </div>
  );
}
