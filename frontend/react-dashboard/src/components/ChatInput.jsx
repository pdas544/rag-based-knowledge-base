import { useRef, useState } from 'react';
import { useChat } from '../context/ChatContext';

export default function ChatInput() {
  const { activeId, streaming, setStreaming, setMessages } = useChat();
  const [value, setValue] = useState('');
  const ref = useRef(null);

  const send = async () => {
    const text = value.trim();
    if (!text || streaming || !activeId) return;
    setValue('');
    setStreaming(true);
    setMessages((prev) => [...prev, { role: 'user', content: text }]);

    try {
      const token = localStorage.getItem('token');
      const res = await fetch(`http://localhost:82/api/conversations/${activeId}/messages`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'text/event-stream',
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({ content: text }),
      });

      const reader = res.body.getReader();
      const decoder = new TextDecoder();
      let buffer = '';
      let assistant = '';

      setMessages((prev) => [...prev, { role: 'assistant', content: '' }]);

      for (;;) {
        const { done, value: chunk } = await reader.read();
        if (done) break;
        buffer += decoder.decode(chunk, { stream: true });
        const parts = buffer.split('\n\n');
        buffer = parts.pop() || '';
        for (const part of parts) {
          const line = part.trim();
          if (!line.startsWith('data:')) continue;
          const payload = JSON.parse(line.slice(5));
          if (payload.delta) {
            assistant += payload.delta;
            setMessages((prev) => {
              const next = [...prev];
              next[next.length - 1] = { role: 'assistant', content: assistant };
              return next;
            });
          }
        }
      }
    } catch {
      setMessages((prev) => [...prev, { role: 'assistant', content: 'Request failed. Retry.' }]);
    } finally {
      setStreaming(false);
    }
  };

  return (
    <div className="mt-4 flex gap-2">
      <textarea
        ref={ref}
        value={value}
        disabled={streaming || !activeId}
        onChange={(e) => setValue(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            send();
          }
        }}
        placeholder={activeId ? 'Type a message...' : 'Select or create a conversation...'}
        rows={2}
        className="flex-1 px-3 py-2 border border-slate-200 rounded-lg text-sm resize-y"
      />
      <button
        onClick={send}
        disabled={streaming || !value.trim() || !activeId}
        className="px-4 py-2 bg-indigo-600 disabled:bg-slate-200 text-white disabled:text-slate-500 text-sm rounded-lg"
      >
        {streaming ? '...' : 'Send'}
      </button>
    </div>
  );
}
