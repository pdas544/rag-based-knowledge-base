// Single source of truth for the API origin.
// Dev: Laravel/Nginx container on http://localhost:82/api
// Prod: same-origin "/api" (injected at build time via VITE_API_BASE_URL)
export const API_BASE = (
  import.meta.env.VITE_API_BASE_URL || 'http://localhost:82/api'
).replace(/\/$/, '');
