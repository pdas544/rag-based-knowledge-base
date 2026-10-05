/* eslint-disable react-refresh/only-export-components */
import { createContext, useCallback, useContext, useState } from 'react';

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

// Phase 1: REST list + cursor messages + SSE streaming state.
export const ChatProvider = ({ children }) => {
  const [conversations, setConversations] = useState([]);
  const [activeId, setActiveId] = useState(null);
  const [messages, setMessages] = useState([]);
  const [streaming, setStreaming] = useState(false);
  const [quota, setQuota] = useState(null);

  const refreshConversations = useCallback(async () => {
    const token = localStorage.getItem('token');
    if (!token) return;
    const res = await fetch('http://localhost:82/api/conversations', {
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
    const token = localStorage.getItem('token');
    const res = await fetch(`http://localhost:82/api/conversations/${id}/messages?limit=20`, {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    if (!res.ok) return;
    setMessages(toList(await res.json()));
  }, []);

  const refreshQuota = useCallback(async () => {
    const token = localStorage.getItem('token');
    if (!token) return;
    const res = await fetch('http://localhost:82/api/quotas', {
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });
    if (res.ok) setQuota(await res.json());
  }, []);

  return (
    <ChatContext.Provider
      value={{
        conversations, setConversations, refreshConversations, createConversation,
        activeId, selectConversation, messages, setMessages,
        streaming, setStreaming, quota, refreshQuota,
      }}
    >
      {children}
    </ChatContext.Provider>
  );
};
