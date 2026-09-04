import { useState } from 'react';
import api from '../axios';
import { useNavigate } from 'react-router-dom';
import { toast } from 'react-hot-toast';

export default function Register({ onRegister }) {
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [error, setError] = useState('');
  const navigate = useNavigate();

  const handleRegister = async (e) => {
    e.preventDefault();
    setError('');
    if (password !== confirmPassword) {
      setError('Passwords do not match');
      toast.error('Passwords do not match');
      return;
    }
    try {
      const res = await api.post('/register', {
        name,
        email,
        password,
        password_confirmation: password,
      });
      onRegister(res.data.user, res.data.token);
      toast.success('Registration successful!');
      if (res.data.user.role === 'admin') {
        navigate('/admin');
      } else {
        navigate('/chat');
      }
    } catch (err) {
      const message = err.response?.data?.message || 'Registration failed';
      setError(message);
      toast.error(message);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-slate-100">
      <form onSubmit={handleRegister} className="bg-white p-8 rounded-xl shadow-md w-full max-w-md">
        <h2 className="text-2xl font-bold mb-6 text-center">Register to AI Knowledge Base</h2>
        {error && <div className="mb-4 text-red-600 text-sm">{error}</div>}
        <div className="mb-4">
          <label className="block text-sm font-medium text-slate-700 mb-1">Name</label>
          <input
            type="text"
            value={name}
            onChange={(e) => setName(e.target.value)}
            className="w-full px-3 py-2 border border-slate-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500"
            required
          />
        </div>
        <div className="mb-4">
          <label className="block text-sm font-medium text-slate-700 mb-1">Email</label>
          <input
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            className="w-full px-3 py-2 border border-slate-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500"
            required
          />
        </div>
        <div className="mb-4">
          <label className="block text-sm font-medium text-slate-700 mb-1">Password</label>
          <input
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            className="w-full px-3 py-2 border border-slate-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500"
            required
          />
        </div>
        <div className="mb-6">
          <label className="block text-sm font-medium text-slate-700 mb-1">Confirm Password</label>
          <input
            type="password"
            value={confirmPassword}
            onChange={(e) => setConfirmPassword(e.target.value)}
            className="w-full px-3 py-2 border border-slate-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500"
            required
          />
        </div>
        <button
          type="submit"
          className="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 rounded-md transition"
        >
          Register
        </button>
        <p className="mt-4 text-center text-sm text-slate-500">
          Already have an account?{' '}
          <a href="#" className="font-medium text-indigo-600 hover:text-indigo-500" onClick={(e) => {
            e.preventDefault();
            navigate('/login');
          }}>
            Login here
          </a>
        </p>
      </form>
    </div>
  );
}