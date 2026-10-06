import { useState } from 'react';
import { MessageSquare } from 'lucide-react';
import { Virtuoso } from 'react-virtuoso';
import ChatSidebar from '../components/ChatSidebar';
import ChatMessage from '../components/ChatMessage';
import ChatInput from '../components/ChatInput';
import { ChatProvider, useChat } from '../context/ChatContext';

const FIRST_INDEX = 10000;

function ChatMain() {
  const { activeId, messages, loadMore, nextCursor } = useChat();
  // Fresh index per conversation: Virtuoso remounts via key={activeId}.
  const [firstItemIndex, setFirstItemIndex] = useState(FIRST_INDEX);

  // 3.6 Infinite scroll: prepend older page, shift index so view stays put.
  const loadOlder = async () => {
    const n = await loadMore();
    if (n > 0) setFirstItemIndex((i) => i - n);
  };

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
        {nextCursor && (
          <button
            onClick={loadOlder}
            className="mb-2 self-center px-3 py-1 text-xs text-indigo-600 hover:text-indigo-800 border border-indigo-200 rounded-full"
          >
            Load older messages
          </button>
        )}
        <div className="flex-1 min-h-0">
          {messages.length === 0 ? (
            <div className="bg-slate-50 rounded-lg border border-dashed border-slate-200 p-6 flex items-center justify-center text-slate-400 text-sm h-full">
              Chat history will appear here...
            </div>
          ) : (
            <Virtuoso
              key={activeId}
              style={{ height: '100%' }}
              firstItemIndex={firstItemIndex}
              initialTopMostItemIndex={Math.max(0, messages.length - 1)}
              alignToBottom
              followOutput="auto"
              data={messages}
              itemContent={(_, m) => (
                <ChatMessage role={m.role} content={m.content} sources={m.sources ?? []} />
              )}
            />
          )}
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
