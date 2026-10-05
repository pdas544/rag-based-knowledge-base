import { useCallback, useEffect, useRef, useState } from 'react';
import { FileUp, Upload } from 'lucide-react';

const API = 'http://localhost:82/api';
const TERMINAL = ['ready', 'rejected', 'failed'];

const badge = (status) => {
  const map = {
    pending: 'bg-slate-100 text-slate-600',
    processing: 'bg-amber-100 text-amber-700',
    awaiting_review: 'bg-blue-100 text-blue-700',
    ready: 'bg-emerald-100 text-emerald-700',
    rejected: 'bg-red-100 text-red-700',
    failed: 'bg-red-100 text-red-700',
  };

  return `text-[10px] px-1.5 py-0.5 rounded font-medium ${map[status] || map.pending}`;
};

// 2.11 drag-drop upload with progress + status badges (10 docs/30d quota note).
export default function DocumentUploadZone() {
  const [docs, setDocs] = useState([]);
  const [progress, setProgress] = useState(null);
  const [drag, setDrag] = useState(false);
  const inputRef = useRef(null);

  const auth = useCallback(() => ({ Authorization: `Bearer ${localStorage.getItem('token')}` }), []);

  const refresh = useCallback(async () => {
    const res = await fetch(`${API}/documents`, { headers: { Accept: 'application/json', ...auth() } });
    if (!res.ok) return;
    const json = await res.json();
    setDocs(json.data ?? []);
  }, [auth]);

  // Fetch-on-mount: intentional (own documents list).
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    refresh();
  }, [refresh]);

  // Poll while anything is non-terminal
  useEffect(() => {
    if (!docs.some((d) => !TERMINAL.includes(d.status))) return;
    const t = setInterval(refresh, 4000);

    return () => clearInterval(t);
  }, [docs, refresh]);

  const upload = useCallback((file) => {
    if (!file || progress !== null) return;
    setProgress(0);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', `${API}/documents`);
    xhr.setRequestHeader('Authorization', `Bearer ${localStorage.getItem('token')}`);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable) setProgress(Math.round((e.loaded / e.total) * 100));
    };
    xhr.onload = () => {
      setProgress(null);
      refresh();
    };
    xhr.onerror = () => setProgress(null);
    const form = new FormData();
    form.append('file', file);
    xhr.send(form);
  }, [progress, refresh]);

  return (
    <div className="border-t border-slate-200 p-3 space-y-2">
      <div className="text-[11px] font-semibold uppercase text-slate-500">Knowledge docs</div>
      <div
        onClick={() => inputRef.current?.click()}
        onDragOver={(e) => { e.preventDefault(); setDrag(true); }}
        onDragLeave={() => setDrag(false)}
        onDrop={(e) => { e.preventDefault(); setDrag(false); upload(e.dataTransfer.files?.[0]); }}
        className={`border border-dashed rounded-lg p-3 text-center cursor-pointer text-xs transition ${
          drag ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-300 text-slate-500 hover:border-indigo-400'
        }`}
      >
        <Upload className="w-4 h-4 mx-auto mb-1" />
        {progress !== null ? `Uploading ${progress}%...` : 'Drop PDF/DOCX/image here or click'}
        <input
          ref={inputRef}
          type="file"
          className="hidden"
          accept=".pdf,.docx,.jpeg,.jpg,.png,.webp,.txt,.md"
          onChange={(e) => upload(e.target.files?.[0])}
        />
      </div>
      <p className="text-[10px] text-slate-400">10 docs / 30 days. Uploads need admin review.</p>
      <div className="space-y-1 max-h-40 overflow-y-auto">
        {docs.slice(0, 10).map((d) => (
          <div key={d.id} className="flex items-center gap-1.5 text-xs text-slate-600 truncate">
            <FileUp className="w-3 h-3 shrink-0" />
            <span className="truncate flex-1">{d.filename}</span>
            <span className={badge(d.status)}>{d.status}</span>
          </div>
        ))}
      </div>
    </div>
  );
}
