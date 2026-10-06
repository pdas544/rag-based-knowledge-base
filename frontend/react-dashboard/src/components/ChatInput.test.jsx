import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import ChatInput from './ChatInput';
import ChatSidebar from './ChatSidebar';
import { ChatProvider } from '../context/ChatContext';

// Vitest runs without globals, so RTL can't auto-register cleanup.
afterEach(() => cleanup());

beforeEach(() => {
  localStorage.setItem('token', 'test-token');
});

const renderChat = () => render(
  <ChatProvider>
    <ChatSidebar />
    <ChatInput />
  </ChatProvider>
);

describe('ChatInput stop button', () => {
  it('aborts the stream without a failure notice', async () => {
    globalThis.fetch = vi.fn((url, opts = {}) => {
      if (String(url).endsWith('/api/conversations') && opts.method === 'POST') {
        return Promise.resolve({ ok: true, json: () => Promise.resolve({ conversation: { id: 1, title: 'T' } }) });
      }
      if (String(url).includes('/messages')) {
        // Hanging stream that honors abort, like a real SSE response.
        return new Promise((_, reject) => {
          opts.signal?.addEventListener('abort', () => {
            reject(new DOMException('Aborted', 'AbortError'));
          });
        });
      }

      return Promise.resolve({ ok: true, json: () => Promise.resolve({ data: [] }) });
    });

    renderChat();

    fireEvent.click(screen.getByText('New chat'));
    const box = await screen.findByPlaceholderText('Type a message...');
    fireEvent.change(box, { target: { value: 'hello' } });
    fireEvent.click(screen.getByText('Send'));

    expect(await screen.findByText('Stop')).toBeTruthy();

    fireEvent.click(screen.getByText('Stop'));

    await waitFor(() => expect(screen.getByText('Send')).toBeTruthy());
    expect(screen.queryByText('Request failed. Retry.')).toBeNull();
  });
});
