/* eslint-disable react-refresh/only-export-components */
import { createContext, useCallback, useContext, useState } from 'react';

const ChatContext = createContext(null);

export const useChat = () => {
  const ctx = useContext(ChatContext);
  if (!ctx) throw new Error('useChat must be used within ChatProvider');
  return ctx;
};

// Phase 0 shell — Phase 1 wires REST/SSE.
export const ChatProvider = ({ children }) => {
  const [conversations, setConversations] = useState([]);
  const [activeId, setActiveId] = useState(null);
  const [messages, setMessages] = useState([]);
  const [streaming, setStreaming] = useState(false);

  const selectConversation = useCallback((id) => {
    setActiveId(id);
    setMessages([]);
  }, []);

  return (
    <ChatContext.Provider
      value={{ conversations, setConversations, activeId, selectConversation, messages, setMessages, streaming, setStreaming }}
    >
      {children}
    </ChatContext.Provider>
  );
};
