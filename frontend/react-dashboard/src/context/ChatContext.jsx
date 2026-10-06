/* eslint-disable react-refresh/only-export-components */
import { createContext, useCallback, useContext, useRef, useState } from 'react';

const ChatContext = createContext(null);

export const useChat = () => {
  const ctx = useContext(ChatContext);
  if (!ctx) throw new Error('useChat must be used within ChatProvider');
  return ctx;
};

// API list shapes vary (Laravel paginator {data:[]}, bare array, error
// objects). Normalize to an array so .map() can never throw in render.
export const toList = (json) => {
  if (Array.isArray(json)) return json;
  if (Array.isArray(json?.data)) return json.data;
  if (Array.isArray(json?.conversations)) return json.conversations;
  if (Array.isArray(json?.messages)) return json.messages;

  return [];
};

// REST list + cursor messages + SSE streaming state (send/stop live here
// so ChatInput stays thin and retry can reuse the same flow).
export const ChatProvider = ({ children }) => {
  const [conversations, setConversations] = useState([]);
  const [activeId, setActiveId] = useState(null);
  const [messages, setMessages] = useState([]);
  const [streaming, setStreaming] = useState(false);
  const [quota, setQuota] = useState(null);
  const [nextCursor, setNextCursor] = useState(null);
  const abortRef = useRef(null);

  const refreshConversations = useCallback(async (search = '') => {
    const token = localStorage.getItem('token');
    if (!token) return;
    const qs = search ? `?search=${encodeURIComponent(search)}` : '';
    const res = await fetch(`http://localhost:82/api/conversations${qs}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    if (!res.ok) return;
    const list = toList(await res.json());
    setConversations(list);
    if (!activeId && list.length > 0) setActiveId(list[0].id);
  }, [activeId]);

  const createConversation = useCallback(async () => {
    const token = localStorage.getItem('token');
    const res = await fetch('http://localhost:82/api/conversations', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify({}),
    });
    if (!res.ok) return null;
    const json = await res.json();
    const convo = json.conversation;
    if (!convo?.id) return null;
    setConversations((prev) => [convo, ...(Array.isArray(prev) ? prev : [])]);
    setActiveId(convo.id);
    setMessages([]);

    return convo;
  }, []);

  const selectConversation = useCallback(async (id) => {
    setActiveId(id);
    setMessages([]);
    setNextCursor(null);
    const token = localStorage.getItem('token');
    const res = await fetch(`http://localhost:82/api/conversations/${id}/messages?limit=20`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    if (!res.ok) return;
    const json = await res.json();
    setMessages(toList(json));
    setNextCursor(json.next_cursor ?? null);
  }, []);

  // 3.6 Infinite scroll: prepend the next older page.
  const loadMore = useCallback(async () => {
    if (!activeId || !nextCursor) return;
    const token = localStorage.getItem('token');
    const res = await fetch(`http://localhost:82/api/conversations/${activeId}/messages?limit=20&cursor=${nextCursor}`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    if (!res.ok) return;
    const json = await res.json();
    const older = toList(json);
    if (older.length > 0) setMessages((prev) => [...older, ...prev]);
    setNextCursor(json.next_cursor ?? null);

    return older.length;
  }, [activeId, nextCursor]);

  const refreshQuota = useCallback(async () => {
    const token = localStorage.getItem('token');
    if (!token) return;
    const res = await fetch('http://localhost:82/api/quotas', {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    if (res.ok) setQuota(await res.json());
  }, []);

  const stopStreaming = useCallback(() => {
    abortRef.current?.abort();
  }, []);

  const sendMessage = useCallback(async (text, conversationId) => {
    const id = conversationId ?? activeId;
    const content = text.trim();
    if (!content || !id) return;
    setStreaming(true);
    setMessages((prev) => [...prev, { role: 'user', content }]);

    const controller = new AbortController();
    abortRef.current = controller;

    try {
      const token = localStorage.getItem('token');
      const res = await fetch(`http://localhost:82/api/conversations/${id}/messages`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'text/event-stream',
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({ content }),
        signal: controller.signal,
      });

      const reader = res.body.getReader();
      const decoder = new TextDecoder();
      let buffer = '';
      let assistant = '';

      setMessages((prev) => [...prev, { role: 'assistant', content: '', sources: [] }]);

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
              next[next.length - 1] = { role: 'assistant', content: assistant, sources: next[next.length - 1]?.sources ?? [] };

              return next;
            });
          }
          if (payload.done && payload.sources) {
            const sources = payload.sources;
            setMessages((prev) => {
              const next = [...prev];
              next[next.length - 1] = { ...next[next.length - 1], sources };

              return next;
            });
          }
        }
      }
      refreshQuota();
    } catch (err) {
      if (err?.name !== 'AbortError') {
        setMessages((prev) => [...prev, { role: 'assistant', content: 'Request failed. Retry.', sources: [] }]);
      }
    } finally {
      abortRef.current = null;
      setStreaming(false);
    }
  }, [activeId, refreshQuota]);

  // 3.5 Retry: re-ask the last user message as a fresh turn.
  const retryLast = useCallback(async () => {
    const lastUser = [...messages].reverse().find((m) => m.role === 'user');
    if (lastUser && !streaming) {
      await sendMessage(lastUser.content);
    }
  }, [messages, streaming, sendMessage]);

  return (
    <ChatContext.Provider
      value={{
        conversations, setConversations, refreshConversations, createConversation,
        activeId, selectConversation, messages, setMessages,
        streaming, setStreaming, quota, refreshQuota,
        sendMessage, stopStreaming, retryLast, loadMore, nextCursor,
      }}
    >
      {children}
    </ChatContext.Provider>
  );
};
