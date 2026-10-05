import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import ChatSidebar from './ChatSidebar';
import { ChatProvider } from '../context/ChatContext';

// Regression: ChatSidebar crashed with
// "TypeError: conversations.map is not a function" whenever the
// conversations payload wasn't an array (blank page, no error boundary).
//
// NOTE: the empty state renders before fetches resolve, so every test
// below first waits for the fetch cycle — otherwise assertions pass
// vacuously on initial state and the crash lands after the test ends.

const renderSidebar = () => render(
  <ChatProvider>
    <ChatSidebar />
  </ChatProvider>
);

// Vitest runs without globals, so RTL can't auto-register cleanup —
// unmount manually or renders accumulate across tests.
afterEach(() => cleanup());

beforeEach(() => {
  localStorage.setItem('token', 'test-token');
});

const mockApi = (conversationsPayload) => {
  globalThis.fetch = vi.fn((url) => {
    if (String(url).includes('/documents')) {
      return Promise.resolve({ ok: true, json: () => Promise.resolve({ data: [] }) });
    }
    if (String(url).includes('/quotas')) {
      return Promise.resolve({ ok: true, json: () => Promise.resolve({ prompts_used: 0, prompts_limit: 30 }) });
    }

    return Promise.resolve({ ok: true, json: () => Promise.resolve(conversationsPayload) });
  });
};

// Let the fetch -> setState -> re-render cycle finish, then assert.
const settle = async () => {
  await waitFor(() => expect(globalThis.fetch).toHaveBeenCalled());
  await waitFor(() => expect(screen.getByText('New chat')).toBeTruthy());
};

describe('ChatSidebar list shapes', () => {
  it('renders Laravel paginator shape {data: [...]}', async () => {
    mockApi({ current_page: 1, data: [{ id: 7, title: 'Hello' }] });
    renderSidebar();
    expect(await screen.findByText('Hello')).toBeTruthy();
  });

  it('survives a 200 object without a data array (the reported crash)', async () => {
    mockApi({ message: 'ok' });
    renderSidebar();
    await settle();
    expect(screen.getByText('No conversations yet')).toBeTruthy();
  });

  it('renders a bare array payload', async () => {
    mockApi([{ id: 9, title: 'Bare' }]);
    renderSidebar();
    expect(await screen.findByText('Bare')).toBeTruthy();
  });

  it('renders a {conversations: [...]} payload', async () => {
    mockApi({ conversations: [{ id: 3, title: 'ViaKey' }] });
    renderSidebar();
    expect(await screen.findByText('ViaKey')).toBeTruthy();
  });

  it('survives null payload', async () => {
    mockApi(null);
    renderSidebar();
    await settle();
    expect(screen.getByText('No conversations yet')).toBeTruthy();
  });

  it('ignores failed responses without crashing', async () => {
    globalThis.fetch = vi.fn(() => Promise.resolve({ ok: false }));
    renderSidebar();
    await settle();
    expect(screen.getByText('No conversations yet')).toBeTruthy();
  });
});
