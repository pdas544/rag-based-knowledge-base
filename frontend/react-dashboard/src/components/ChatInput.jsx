import { useRef, useState } from 'react';
import { useChat } from '../context/ChatContext';

export default function ChatInput() {
  const { activeId, streaming, sendMessage, stopStreaming } = useChat();
  const [value, setValue] = useState('');
  const ref = useRef(null);

  const send = () => {
    const text = value.trim();
    if (!text || streaming || !activeId) return;
    setValue('');
    sendMessage(text);
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
      {streaming ? (
        <button
          onClick={stopStreaming}
          title="Stop response"
          className="px-4 py-2 bg-slate-700 hover:bg-slate-800 text-white text-sm rounded-lg flex items-center gap-2"
        >
          <span className="inline-block w-3 h-3 bg-white rounded-[2px]" /> Stop
        </button>
      ) : (
        <button
          onClick={send}
          disabled={!value.trim() || !activeId}
          className="px-4 py-2 bg-indigo-600 disabled:bg-slate-200 text-white disabled:text-slate-500 text-sm rounded-lg"
        >
          Send
        </button>
      )}
    </div>
  );
}
