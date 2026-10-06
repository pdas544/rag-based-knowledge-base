import { Copy, RotateCcw } from 'lucide-react';
import ReactMarkdown from 'react-markdown';
import hljs from 'highlight.js';
import DOMPurify from 'dompurify';
import 'highlight.js/styles/github.css';
import { useChat } from '../context/ChatContext';

// 3.5 Markdown + highlight.js code blocks (sanitized) + citation badges + retry.
function CodeBlock({ className, children }) {
  const text = String(children ?? '').replace(/\n$/, '');
  const lang = /language-([\w-]+)/.exec(className || '')?.[1];
  const isBlock = lang || text.includes('\n');

  if (!isBlock) {
    return <code className={className}>{children}</code>;
  }

  let html;
  try {
    html = lang && hljs.getLanguage(lang)
      ? hljs.highlight(text, { language: lang }).value
      : hljs.highlightAuto(text).value;
  } catch {
    return <code className={className}>{children}</code>;
  }
  if (!html) {
    return <code className={className}>{children}</code>;
  }

  return <code dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(html) }} />;
}

export default function ChatMessage({ role, content, sources = [] }) {
  const { retryLast, streaming } = useChat();
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
        {isUser ? (
          <div>{content}</div>
        ) : (
          <div className="markdown-body">
            <ReactMarkdown components={{ code: CodeBlock }}>{content}</ReactMarkdown>
          </div>
        )}
        {!isUser && sources.length > 0 && (
          <div className="mt-2 flex flex-wrap gap-1">
            {sources.map((s, i) => (
              <span
                key={s.point_id || i}
                title={`score ${typeof s.score === 'number' ? s.score.toFixed(3) : s.score ?? 'keyword'}`}
                className="text-[10px] px-1.5 py-0.5 rounded bg-indigo-50 text-indigo-700 font-medium"
              >
                [{s.title || `doc #${s.doc_id ?? '?'}`}]
              </span>
            ))}
          </div>
        )}
        {!isUser && (
          <div className="mt-1 flex items-center gap-3">
            <button onClick={copy} className="text-xs text-slate-400 hover:text-slate-600 flex items-center gap-1">
              <Copy className="w-3 h-3" /> Copy
            </button>
            <button
              onClick={retryLast}
              disabled={streaming}
              title="Re-ask the last message as a fresh turn"
              className="text-xs text-slate-400 hover:text-slate-600 disabled:opacity-40 flex items-center gap-1"
            >
              <RotateCcw className="w-3 h-3" /> Retry
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
