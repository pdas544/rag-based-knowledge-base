import axios from 'axios';
import toast from 'react-hot-toast';

// We'll use a simple loading state that is set by the App component via a setter.
// We'll have a variable that holds the setter function.
let setLoadingGlobal = () => {};
let handleUnauthorized = () => {};

// This function is called by the App component to set the setter.
export const setLoadingGlobalFn = (fn) => {
  setLoadingGlobal = fn;
};

export const setUnauthorizedHandlerFn = (fn) => {
  handleUnauthorized = fn;
};

const api = axios.create({
  baseURL: 'http://localhost:82/api',
});

// Request interceptor
api.interceptors.request.use(
  (config) => {
    // Call the setter to show loading
    setLoadingGlobal(true);
    return config;
  },
  (error) => {
    // If there's an error, we still want to hide loading
    setLoadingGlobal(false);
    return Promise.reject(error);
  }
);

// Response interceptor
api.interceptors.response.use(
  (response) => {
    // Hide loading on successful response
    setLoadingGlobal(false);
    return response;
  },
  (error) => {
    // Hide loading on error response
    setLoadingGlobal(false);

    const status = error.response?.status;
    const message = error.response?.data?.message || 'An unexpected error occurred';

    if (status === 401) {
      toast.error('Session expired. Please login again.');
      handleUnauthorized();
    } else if (status === 403) {
      toast.error('You do not have permission to perform this action.');
    } else if (status >= 500) {
      toast.error('Server error. Please try again later.');
    } else if (!status) {
      toast.error('Network error. Please check your connection.');
    } else {
      toast.error(message);
    }

    return Promise.reject(error);
  }
);

export default api;